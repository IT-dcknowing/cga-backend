<?php

namespace App\Domain\Obligations\Actions;

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Enums\MemberObligationStatus;
use App\Domain\Obligations\Enums\ObligationKind;
use App\Domain\Obligations\Enums\ObligationPeriodicity;
use App\Domain\Obligations\Models\MemberObligation;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Obligations\Support\DueDateCalculator;
use App\Domain\Obligations\Support\ObligationPeriod;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Models\Organization;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Génération des échéances (F-OBL-03, RG-OBL-04, 06), chaque nuit par `obligations:generate` : pour
 * chaque obligation confirmée d'un dossier validé, les périodes dont l'échéance tombe entre le début de
 * l'obligation et l'horizon paramétré (3 mois glissants par défaut) ; échéance calculée par la règle
 * du catalogue puis reportée au jour ouvré. Rejouable : une période déjà générée n'est jamais recréée.
 * Une obligation « déclaration puis paiement » ne génère que la déclaration : son paiement naît quand
 * elle est faite (RG-OBL-05). Une obligation sur événement n'a pas d'échéance automatique.
 */
class GenerateObligationInstances
{
    private const CHUNK = 200;

    /**
     * Horizon de chaque CGA, en mois, lu une fois par exécution.
     *
     * @var array<string, int>
     */
    private array $horizons = [];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly DueDateCalculator $dueDates,
    ) {}

    /**
     * @return int nombre d'échéances créées
     */
    public function execute(?CarbonImmutable $day = null): int
    {
        $day ??= CarbonImmutable::today();

        return Tenancy::bypass(function () use ($day): int {
            $created = 0;
            $this->obligations()->with('type')->chunkById(self::CHUNK, function (Collection $obligations) use ($day, &$created): void {
                $created += DB::transaction(fn (): int => $obligations->sum(fn (MemberObligation $obligation): int => $this->generate($obligation, $day)));
            });

            return $created;
        }, 'Tâche obligations:generate : échéances des obligations confirmées dans tous les CGA (RG-OBL-04).');
    }

    /**
     * Obligations confirmées, encore en cours, des dossiers validés des CGA non résiliés.
     *
     * @return Builder<MemberObligation>
     */
    private function obligations(): Builder
    {
        return MemberObligation::query()
            ->where('status', MemberObligationStatus::Active)
            ->whereHas('member', static fn (Builder $member) => $member->whereIn('status', [MemberStatus::Active, MemberStatus::RadiationPending]))
            ->whereIn('organization_id', Organization::query()->where('status', '<>', OrganizationStatus::Terminated)->select('id'));
    }

    private function generate(MemberObligation $obligation, CarbonImmutable $day): int
    {
        $type = $obligation->type;
        $kind = match ($type->kind) {
            ObligationKind::Declaration, ObligationKind::DeclarationPayment => InstanceKind::Declaration,
            ObligationKind::Payment => InstanceKind::Payment,
            ObligationKind::Diligence => InstanceKind::Diligence,
        };
        $start = CarbonImmutable::parse($obligation->start_date);
        $until = $day->addMonthsNoOverflow($this->horizon($obligation->organization_id));
        $end = $obligation->end_date === null ? null : CarbonImmutable::parse($obligation->end_date);
        $existing = ObligationInstance::query()
            ->where('member_obligation_id', $obligation->id)
            ->where('kind', $kind)
            ->pluck('period_label')
            ->flip();

        // Une période qui finit avant le début de l'obligation peut encore échoir après lui : on
        // repart d'un an plus tôt et l'on garde les échéances postérieures au début.
        $period = ObligationPeriod::containing($type->periodicity, $type->periodicity === ObligationPeriodicity::OneOff ? $start : $start->subYear());
        $created = 0;

        while ($period !== null) {
            $due = $this->dueDates->dueDate($type->due_rule, $period);
            if ($due->greaterThan($until)) {
                break;
            }

            $open = $end === null || $period->start->lessThanOrEqualTo($end);
            if ($open && $due->greaterThanOrEqualTo($start) && ! $existing->has($period->label)) {
                $this->create($obligation, $kind, $period, $due);
                $created++;
            }

            $period = $period->next($type->periodicity);
        }

        return $created;
    }

    private function create(MemberObligation $obligation, InstanceKind $kind, ObligationPeriod $period, CarbonImmutable $due): void
    {
        $type = $obligation->type;
        $instance = new ObligationInstance;
        $instance->organization_id = $obligation->organization_id;
        $instance->member_id = $obligation->member_id;
        $instance->member_obligation_id = $obligation->id;
        $instance->obligation_type_id = $type->id;
        $instance->label = $type->label;
        $instance->category = $type->category;
        $instance->kind = $kind;
        $instance->authority_name = $type->authority;
        $instance->period_label = $period->label;
        $instance->period_start = $period->start;
        $instance->period_end = $period->end;
        $instance->due_date = $due;
        $instance->platform = $type->platform;
        $instance->tax_nature = $type->tax_nature;
        $instance->status = match ($kind) {
            InstanceKind::Declaration => InstanceStatus::Todo,
            InstanceKind::Payment => InstanceStatus::ToPay,
            InstanceKind::Diligence => InstanceStatus::NotEstablished,
        };
        $instance->save();
    }

    private function horizon(string $organizationId): int
    {
        return $this->horizons[$organizationId] ??= (int) $this->settings->get('obligations.horizon_months', $organizationId);
    }
}
