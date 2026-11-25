<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Members\Enums\FeesPeriodicity;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Payments\Enums\ChargeKind;
use App\Domain\Payments\Enums\ChargeStatus;
use App\Domain\Payments\Models\MemberCharge;
use App\Domain\Payments\Support\ChargeAmounts;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Échéances d'honoraires de la période en cours (F-PAY-04, tâche fees:generate chaque nuit) : pour
 * chaque dossier validé (actif ou en radiation) dont les honoraires contractuels ne sont pas nuls
 * (RG-PAY-01), une somme due par période selon sa périodicité (mois, trimestre ou année civile), du
 * montant hors taxe du dossier, TVA en sus (ChargeAmounts), échue le jour fees.due_day de la période (1er par défaut, réglable par CGA).
 * Idempotente : une période déjà ouverte n'est jamais recréée (index unique member_id, kind,
 * period_start). Tous les CGA en activité, par lots (contournement journalisé).
 */
class GenerateFeeCharges
{
    private const CHUNK = 500;

    /**
     * Jour d'échéance de chaque CGA, lu une fois par exécution.
     *
     * @var array<string, int>
     */
    private array $dueDays = [];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly ChargeAmounts $amounts,
    ) {}

    /**
     * @return int nombre d'échéances créées
     */
    public function execute(?CarbonImmutable $day = null): int
    {
        $day ??= CarbonImmutable::today();

        return Tenancy::bypass(function () use ($day): int {
            $created = 0;
            $this->members($day)->chunkById(self::CHUNK, function (Collection $members) use ($day, &$created): void {
                $created += DB::transaction(fn (): int => $this->generate($members, $day));
            });

            return $created;
        }, 'Tâche fees:generate : échéances d\'honoraires de la période dans tous les CGA (F-PAY-04).');
    }

    /**
     * Dossiers validés aux honoraires non nuls, adhérents au plus tard ce jour, des CGA non résiliés.
     *
     * @return Builder<Member>
     */
    private function members(CarbonImmutable $day): Builder
    {
        return Member::query()
            ->whereIn('status', [MemberStatus::Active, MemberStatus::RadiationPending])
            ->where('fees_amount', '>', 0)
            ->whereNotNull('adhesion_date')
            ->whereDate('adhesion_date', '<=', $day)
            ->whereIn('organization_id', Organization::query()->where('status', '<>', OrganizationStatus::Terminated)->select('id'));
    }

    /**
     * @param  Collection<int, Member>  $members
     */
    private function generate(Collection $members, CarbonImmutable $day): int
    {
        $periods = $members->mapWithKeys(fn (Member $member): array => [$member->id => $this->periodStart($member->fees_periodicity, $day)]);
        $existing = MemberCharge::query()
            ->where('kind', ChargeKind::Fees)
            ->whereIn('member_id', $members->modelKeys())
            ->whereIn('period_start', $periods->map(static fn (CarbonImmutable $start): string => $start->toDateString())->unique()->values())
            ->get(['member_id', 'period_start'])
            ->map(static fn (MemberCharge $charge): string => $charge->member_id.'|'.$charge->period_start?->toDateString())
            ->flip();

        $created = 0;
        foreach ($members as $member) {
            $start = $periods[$member->id];
            if ($existing->has($member->id.'|'.$start->toDateString())) {
                continue;
            }

            $charge = new MemberCharge;
            $charge->organization_id = $member->organization_id;
            $charge->member_id = $member->id;
            $charge->kind = ChargeKind::Fees;
            $charge->period_start = $start;
            $charge->period_end = $this->periodEnd($member->fees_periodicity, $start);
            $charge->due_date = $this->dueDate($start, $member->organization_id);
            $this->amounts->apply($charge, $member->fees_amount, $member->organization_id);
            $charge->amount_paid = 0;
            $charge->status = ChargeStatus::Due;
            $charge->save();
            $created++;
        }

        return $created;
    }

    private function periodStart(FeesPeriodicity $periodicity, CarbonImmutable $day): CarbonImmutable
    {
        return match ($periodicity) {
            FeesPeriodicity::Monthly => $day->startOfMonth(),
            FeesPeriodicity::Quarterly => $day->startOfQuarter(),
            FeesPeriodicity::Yearly => $day->startOfYear(),
        };
    }

    private function periodEnd(FeesPeriodicity $periodicity, CarbonImmutable $start): CarbonImmutable
    {
        return match ($periodicity) {
            FeesPeriodicity::Monthly => $start->endOfMonth()->startOfDay(),
            FeesPeriodicity::Quarterly => $start->endOfQuarter()->startOfDay(),
            FeesPeriodicity::Yearly => $start->endOfYear()->startOfDay(),
        };
    }

    /**
     * Le jour fees.due_day du premier mois de la période, ramené à la fin du mois s'il le dépasse.
     */
    private function dueDate(CarbonImmutable $start, string $organizationId): CarbonImmutable
    {
        $this->dueDays[$organizationId] ??= (int) $this->settings->get('fees.due_day', $organizationId);

        return $start->setDay(min($this->dueDays[$organizationId], $start->daysInMonth));
    }
}
