<?php

namespace App\Domain\Obligations\Actions;

use App\Domain\Members\Models\Member;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Enums\MemberObligationStatus;
use App\Domain\Obligations\Models\MemberObligation;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Obligations\Models\ObligationType;
use App\Domain\Obligations\Support\CatalogVersionPlanner;
use App\Domain\Obligations\Support\DueDateCalculator;
use App\Domain\Obligations\Support\ObligationPeriod;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Application d'une nouvelle version du catalogue national (RG-DATA-04, décision 0005), après l'aperçu
 * confirmé par le CNCGA : la version est chargée (provisoire jusqu'à sa validation) et remplace la
 * version en vigueur ; chaque obligation de dossier garde son code et passe à la nouvelle version ;
 * une obligation retirée prend fin ; les échéances à venir non commencées suivent les nouvelles
 * règles ; les obligations nouvelles applicables sont proposées au CGA (RG-OBL-02). Les échéances
 * passées ou commencées ne changent jamais. Rejouable : une version déjà en vigueur ne change rien.
 */
class ApplyObligationCatalogVersion
{
    private const CHUNK = 200;

    public function __construct(
        private readonly LoadObligationCatalog $load,
        private readonly CatalogVersionPlanner $planner,
        private readonly SuggestMemberObligations $suggest,
        private readonly DueDateCalculator $dueDates,
    ) {}

    /**
     * @param  array<string, mixed>  $catalog
     * @return array{version: string, loaded: int, suggested: int, withdrawn: int, ended: int, rescheduled: int}
     *
     * @throws ValidationException catalogue invalide : rien n'est écrit
     */
    public function execute(array $catalog, CarbonImmutable $today): array
    {
        $validated = $this->load->validate($catalog);

        return Tenancy::bypass(fn (): array => DB::transaction(function () use ($validated, $today): array {
            $previous = $this->planner->current();
            $loaded = $this->load->write($validated);
            $version = $validated['version'];
            $next = ObligationType::query()->whereNull('organization_id')->where('catalog_version', $version)->get()->keyBy('code');

            foreach ($previous as $code => $type) {
                if ($type->catalog_version !== $version || ! $next->has($code)) {
                    $type->active = false;
                    $type->save();
                }
            }

            $summary = ['version' => $version, 'loaded' => $loaded, 'suggested' => 0, 'withdrawn' => 0, 'ended' => 0, 'rescheduled' => 0];
            MemberObligation::query()
                ->with('type')
                ->whereIn('obligation_type_id', $previous->modelKeys())
                ->where('status', '<>', MemberObligationStatus::Removed)
                ->chunkById(self::CHUNK, function (Collection $obligations) use ($previous, $next, $today, &$summary): void {
                    foreach ($obligations as $obligation) {
                        $code = $obligation->type->code;
                        $new = $next->get($code);
                        if ($new === null) {
                            $this->end($obligation, $today);
                            $summary['ended']++;
                        } else {
                            $summary['rescheduled'] += $this->move($obligation, $previous->get($code) ?? $obligation->type, $new, $today);
                        }
                    }
                });

            $this->planner->eligibleMembers()->chunkById(self::CHUNK, function (Collection $members) use (&$summary): void {
                foreach ($members as $member) {
                    /** @var Member $member */
                    $effect = $this->suggest->execute($member);
                    $summary['suggested'] += $effect['added'];
                    $summary['withdrawn'] += $effect['withdrawn'];
                }
            });

            return $summary;
        }), 'Nouvelle version du catalogue des obligations, appliquée aux dossiers de tous les centres (RG-DATA-04).');
    }

    /**
     * Obligation retirée de la version : fin au jour de l'application, échéances à venir non commencées
     * annulées.
     */
    private function end(MemberObligation $obligation, CarbonImmutable $today): void
    {
        $this->planner->upcomingUntouched($obligation, $today)->get()->each(static function (ObligationInstance $instance): void {
            $instance->status = InstanceStatus::Cancelled;
            $instance->save();
        });

        $obligation->status = MemberObligationStatus::Removed;
        $obligation->end_date = $today->max($obligation->start_date);
        $obligation->save();
    }

    /**
     * Obligation conservée : rattachée à la nouvelle version ; ses échéances à venir non commencées
     * changent de période (annulées, régénérées la nuit), de date ou seulement de libellé.
     *
     * @return int échéances annulées ou dont la date change
     */
    private function move(MemberObligation $obligation, ObligationType $old, ObligationType $new, CarbonImmutable $today): int
    {
        if ($obligation->obligation_type_id !== $new->id) {
            $obligation->obligation_type_id = $new->id;
            $obligation->save();
        }

        $periods = $this->planner->changesPeriods($old, $new);
        $changed = 0;
        foreach ($this->planner->upcomingUntouched($obligation, $today)->get() as $instance) {
            if ($periods) {
                $instance->status = InstanceStatus::Cancelled;
                $instance->save();
                $changed++;

                continue;
            }

            $due = $this->dueDates->dueDate($new->due_rule, new ObligationPeriod(
                CarbonImmutable::parse($instance->period_start),
                CarbonImmutable::parse($instance->period_end),
                $instance->period_label,
            ));
            if (! $due->isSameDay($instance->due_date)) {
                $instance->due_date = $due;
                $changed++;
            }
            $instance->obligation_type_id = $new->id;
            $instance->label = $new->label;
            $instance->category = $new->category;
            $instance->platform = $new->platform;
            $instance->tax_nature = $new->tax_nature;
            $instance->save();
        }

        return $changed;
    }
}
