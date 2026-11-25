<?php

namespace App\Domain\Obligations\Support;

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Enums\MemberObligationStatus;
use App\Domain\Obligations\Enums\ObligationKind;
use App\Domain\Obligations\Models\MemberObligation;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Obligations\Models\ObligationType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

/**
 * Effet d'une nouvelle version du catalogue national sur les dossiers (RG-DATA-04, décision 0005),
 * sans rien écrire : obligations ajoutées, retirées ou modifiées ; propositions à créer ou à retirer,
 * obligations des dossiers qui prennent fin, échéances à venir non commencées à recalculer. Les nombres
 * seuls sortent d'ici : jamais le nom d'un adhérent (aperçu montré au CNCGA). À appeler hors espace
 * actif (Tenancy::bypass) : il parcourt tous les centres.
 */
class CatalogVersionPlanner
{
    private const CHUNK = 200;

    /** Champs dont le changement touche les échéances à venir. */
    private const SCHEDULE_FIELDS = ['periodicity', 'kind', 'due_rule'];

    private const DESCRIPTION_FIELDS = ['label', 'category', 'authority', 'platform', 'applicability', 'tax_nature', 'has_abatement', 'creates_payment_on_done', 'legal_basis'];

    public function __construct(private readonly ApplicabilityEngine $engine) {}

    /**
     * Obligations nationales en vigueur, par code.
     *
     * @return Collection<string, ObligationType>
     */
    public function current(): Collection
    {
        return ObligationType::query()->whereNull('organization_id')->where('active', true)->get()->keyBy('code');
    }

    /**
     * @param  Collection<string, ObligationType>  $next  obligations de la nouvelle version, par code
     * @return array{version: string, types: array{added: list<string>, removed: list<string>, changed: list<array{code: string, fields: list<string>}>, unchanged: int}, members: array{suggested: int, withdrawn: int, ended: int, rescheduled: int, members: int, organizations: int}}
     */
    public function plan(string $version, Collection $next, CarbonImmutable $today): array
    {
        $current = $this->current();
        $changed = $next->filter(static fn (ObligationType $type, string $code): bool => $current->has($code))
            ->map(fn (ObligationType $type, string $code): array => ['code' => $code, 'fields' => $this->changes($current->get($code), $type)])
            ->filter(static fn (array $change): bool => $change['fields'] !== [])
            ->values();
        $schedule = $changed->filter(static fn (array $change): bool => array_intersect($change['fields'], self::SCHEDULE_FIELDS) !== [])->pluck('code')->all();

        $impact = ['suggested' => 0, 'withdrawn' => 0, 'ended' => 0, 'rescheduled' => 0];
        $members = $organizations = [];
        $this->eligibleMembers()->with(['sector'])->chunkById(self::CHUNK, function (Collection $chunk) use ($current, $next, $schedule, $today, &$impact, &$members, &$organizations): void {
            $open = MemberObligation::query()->with('type')->whereIn('member_id', $chunk->modelKeys())->where('status', '<>', MemberObligationStatus::Removed)->get()->groupBy('member_id');
            $locals = ObligationType::query()->whereIn('organization_id', $chunk->pluck('organization_id')->unique()->all())->where('active', true)->get()->groupBy('organization_id');

            foreach ($chunk as $member) {
                $effect = $this->memberEffect($member, $open->get($member->id, new Collection), $locals->get($member->organization_id, new Collection), $current, $next, $schedule, $today);
                foreach ($effect as $key => $count) {
                    $impact[$key] += $count;
                }
                if (array_sum($effect) > 0) {
                    $members[$member->id] = true;
                    $organizations[$member->organization_id] = true;
                }
            }
        });

        return [
            'version' => $version,
            'types' => [
                'added' => $next->keys()->diff($current->keys())->values()->all(),
                'removed' => $current->keys()->diff($next->keys())->values()->all(),
                'changed' => $changed->all(),
                'unchanged' => $next->count() - $changed->count() - $next->keys()->diff($current->keys())->count(),
            ],
            'members' => [...$impact, 'members' => count($members), 'organizations' => count($organizations)],
        ];
    }

    /**
     * Dossiers suivis : brouillons, validés et en cours de radiation (RG-OBL-02).
     *
     * @return Builder<Member>
     */
    public function eligibleMembers(): Builder
    {
        return Member::query()->whereIn('status', [MemberStatus::Draft, MemberStatus::Active, MemberStatus::RadiationPending]);
    }

    /**
     * Échéances à venir non commencées d'une obligation de dossier (décision 0005).
     *
     * @return Builder<ObligationInstance>
     */
    public function upcomingUntouched(MemberObligation $obligation, CarbonImmutable $today): Builder
    {
        return ObligationInstance::query()
            ->where('member_obligation_id', $obligation->id)
            ->where('due_date', '>', $today->toDateString())
            ->whereIn('status', [InstanceStatus::Todo, InstanceStatus::ToPay, InstanceStatus::NotEstablished])
            ->where('amount_paid', 0)
            ->whereNull('member_declared_status');
    }

    /**
     * Les échéances changent-elles de période ou de nature (plutôt que de seule date) ?
     */
    public function changesPeriods(ObligationType $from, ObligationType $to): bool
    {
        return $from->periodicity !== $to->periodicity || self::instanceKind($from->kind) !== self::instanceKind($to->kind);
    }

    public static function instanceKind(ObligationKind $kind): InstanceKind
    {
        return match ($kind) {
            ObligationKind::Declaration, ObligationKind::DeclarationPayment => InstanceKind::Declaration,
            ObligationKind::Payment => InstanceKind::Payment,
            ObligationKind::Diligence => InstanceKind::Diligence,
        };
    }

    /**
     * @param  Collection<int, MemberObligation>  $open
     * @param  Collection<int, ObligationType>  $locals
     * @param  Collection<string, ObligationType>  $current
     * @param  Collection<string, ObligationType>  $next
     * @param  list<string>  $schedule
     * @return array{suggested: int, withdrawn: int, ended: int, rescheduled: int}
     */
    private function memberEffect(Member $member, Collection $open, Collection $locals, Collection $current, Collection $next, array $schedule, CarbonImmutable $today): array
    {
        $effect = ['suggested' => 0, 'withdrawn' => 0, 'ended' => 0, 'rescheduled' => 0];
        $national = $open->filter(static fn (MemberObligation $obligation): bool => $obligation->type->isNational() && $current->has($obligation->type->code));

        foreach ($national as $obligation) {
            $code = $obligation->type->code;
            if (! $next->has($code)) {
                $effect['ended']++;
            } elseif (in_array($code, $schedule, true)) {
                $effect['rescheduled'] += $this->upcomingUntouched($obligation, $today)->count();
            }
        }

        $applicable = $this->engine->forMember($member, new Collection([...$next->values()->all(), ...$locals->all()]))
            ->map(static fn (ObligationType $type): string => ($type->isNational() ? 'n:' : 'l:').$type->code);
        $held = $open->map(static fn (MemberObligation $obligation): string => ($obligation->type->isNational() ? 'n:' : 'l:').$obligation->type->code);
        $effect['suggested'] = $applicable->diff($held)->count();
        $effect['withdrawn'] = $open
            ->filter(static fn (MemberObligation $obligation): bool => $obligation->status === MemberObligationStatus::Suggested
                && ! ($obligation->type->isNational() && ! $next->has($obligation->type->code))
                && ! $applicable->contains(($obligation->type->isNational() ? 'n:' : 'l:').$obligation->type->code))
            ->count();

        return $effect;
    }

    /**
     * @return list<string>
     */
    private function changes(ObligationType $from, ObligationType $to): array
    {
        return BaseCollection::make([...self::SCHEDULE_FIELDS, ...self::DESCRIPTION_FIELDS])
            ->filter(static fn (string $field): bool => self::normalized($from->getAttribute($field)) !== self::normalized($to->getAttribute($field)))
            ->values()
            ->all();
    }

    private static function normalized(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }
        if (is_array($value)) {
            ksort($value);

            return (string) json_encode($value);
        }

        return var_export($value, true);
    }
}
