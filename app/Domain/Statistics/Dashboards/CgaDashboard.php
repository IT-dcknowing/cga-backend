<?php

namespace App\Domain\Statistics\Dashboards;

use App\Domain\Closing\Models\ClosingCampaign;
use App\Domain\Closing\Support\ClosingProgress;
use App\Domain\Concerns\Enums\ConcernLevel;
use App\Domain\Concerns\Enums\ConcernStatus;
use App\Domain\Concerns\Models\Concern;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\PieceStatus;
use App\Domain\Members\Enums\RadiationStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\Radiation;
use App\Domain\Obligations\Support\ObligationPlanning;
use App\Domain\Payments\Enums\ChargeKind;
use App\Domain\Payments\Enums\ChargeStatus;
use App\Domain\Payments\Enums\InvoiceStatus;
use App\Domain\Payments\Models\Invoice;
use App\Domain\Payments\Models\MemberCharge;
use App\Domain\Reports\Models\QuarterlyReport;
use App\Domain\Reports\Support\ReportCompleteness;
use App\Domain\Statistics\Models\StatOrgDaily;
use App\Domain\Statistics\Support\OrgStatsSummary;
use App\Domain\Statistics\Support\StatPeriod;
use App\Domain\Statistics\Support\Variation;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tableau de bord d'un CGA (F-DSH-02, 03-APPFLOW §4.3) pour une période (F-DSH-04), lu dans son seul centre :
 * adhérents actifs (agrégats, N-1), dossiers à compléter, honoraires échus, encaissés (agrégats, N-1) et impayés de
 * la période, échéances sous obligations.upcoming_days jours, avancement du rapport du trimestre et alertes
 * (reclassés au réel, honoraires impayés et impayés anciens, pièces attendues, radiations à valider, factures à
 * certifier, clôture en retard, préoccupations sans réponse). Les chiffres d'adhérents portent sur le portefeuille
 * CGA, comme les agrégats et les listes que les alertes ouvrent ; chaque bloc n'est rendu qu'avec son droit.
 */
final class CgaDashboard
{
    public function __construct(
        private readonly OrgStatsSummary $summary,
        private readonly ObligationPlanning $planning,
        private readonly SettingsService $settings,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(StatPeriod $period, CarbonImmutable $today): array
    {
        $organizationId = $this->context->organizationId();
        $previous = $period->previousYear();
        $current = $this->summary->byOrganization($period->from, $period->until($today))[$organizationId] ?? [];
        $before = $this->summary->byOrganization($previous->from, $previous->until($today))[$organizationId] ?? [];
        $value = static fn (array $row, string $metric): int => (int) ($row[$metric] ?? 0);

        $indicators = [];
        if ($this->context->allows('members.view')) {
            $indicators['members'] = [
                'active' => Variation::of($value($current, 'active_members'), $value($before, 'active_members')),
                'new' => Variation::of($value($current, 'new_members'), $value($before, 'new_members')),
                'radiations' => Variation::of($value($current, 'radiations'), $value($before, 'radiations')),
                'to_complete' => Member::query()->regulatory()->where('status', MemberStatus::Draft)->count(),
            ];
        }
        if ($this->context->allows('member_payments.manage')) {
            $indicators['fees'] = [
                'due' => Variation::of($value($current, 'fees_due'), $value($before, 'fees_due')),
                'paid' => Variation::of($value($current, 'fees_paid'), $value($before, 'fees_paid')),
                'unpaid' => $this->unpaidFees($period, $today),
            ];
        }
        if ($this->context->allows('obligations.manage')) {
            $indicators['upcoming'] = $this->planning->upcoming($today, (int) $this->settings->get('obligations.upcoming_days', $organizationId));
        }
        if ($this->context->allows('reports.prepare')) {
            $indicators['report'] = $this->report($today);
        }

        return [
            'period' => $period->toArray(),
            'computed_at' => $this->computedAt(),
            'indicators' => $indicators,
            'alerts' => $this->alerts($organizationId, $today),
        ];
    }

    /**
     * Reste dû des échéances d'honoraires passées en impayé, échues dans la période.
     */
    private function unpaidFees(StatPeriod $period, CarbonImmutable $today): int
    {
        return (int) MemberCharge::query()
            ->where('kind', ChargeKind::Fees)
            ->where('status', ChargeStatus::Overdue)
            ->whereBetween('due_date', [$period->from->toDateString(), $period->until($today)->toDateString()])
            ->whereHas('member', static fn (Builder $member) => $member->regulatory())
            ->selectRaw('coalesce(sum(amount - amount_paid), 0) as unpaid')
            ->value('unpaid');
    }

    /**
     * Rapport du trimestre en cours : statut et avancement (%).
     *
     * @return array{id: string, year: int, quarter: int, status: string, completeness: int}|null
     */
    private function report(CarbonImmutable $today): ?array
    {
        $report = QuarterlyReport::query()->where('year', $today->year)->where('quarter', $today->quarter)->first();

        return $report === null ? null : [
            'id' => $report->id,
            'year' => $report->year,
            'quarter' => $report->quarter,
            'status' => $report->status->value,
            'completeness' => ReportCompleteness::overall((array) $report->completeness),
        ];
    }

    /**
     * Alertes cliquables (03-APPFLOW §4.3), selon les droits.
     *
     * @return list<array{code: string, count: int, label: string, overdue?: int}>
     */
    private function alerts(string $organizationId, CarbonImmutable $today): array
    {
        $alerts = [];
        if ($this->context->allows('members.view')) {
            $deadline = (int) $this->settings->get('rsi.radiation_deadline_days', $organizationId);
            $rsi = Member::query()->regulatory()->where('status', MemberStatus::Active)->whereNotNull('rsi_since');
            $alerts[] = [...$this->alert('rsi_active', (clone $rsi)->count()), 'overdue' => (clone $rsi)->where('rsi_since', '<=', $today->subDays($deadline)->toDateString())->count()];
            $alerts[] = $this->alert('members_to_complete', Member::query()->regulatory()->where('status', MemberStatus::Draft)->count());
            $alerts[] = $this->alert('pieces_pending', Member::query()->regulatory()->open()->whereHas('pieces', static fn (Builder $piece) => $piece->where('status', PieceStatus::Expected))->count());
        }
        if ($this->context->allows('member_payments.manage')) {
            $overdue = static fn (): Builder => Member::query()->regulatory()->whereHas('charges', static fn (Builder $charge) => $charge->where('kind', ChargeKind::Fees)->where('status', ChargeStatus::Overdue));
            $months = (int) $this->settings->get('fees.alert_after_months', $organizationId);
            $alerts[] = [...$this->alert('fees_overdue', $overdue()->count()), 'overdue' => $overdue()->whereHas('charges', static fn (Builder $charge) => $charge
                ->where('kind', ChargeKind::Fees)
                ->where('status', ChargeStatus::Overdue)
                ->where('due_date', '<=', $today->subMonthsNoOverflow($months)->toDateString()))->count()];
        }
        if ($this->context->allows('members.radiation.validate')) {
            $alerts[] = $this->alert('radiations_pending', Radiation::query()->where('status', RadiationStatus::Requested)->count());
        }
        if ($this->context->allows('invoices.issue')) {
            $alerts[] = $this->alert('invoices_pending', Invoice::query()->where('status', InvoiceStatus::PendingCertification)->count());
        }
        if ($this->context->allows('closing.view')) {
            $campaign = ClosingCampaign::query()->orderByDesc('fiscal_year')->first();
            $alerts[] = $this->alert('closing_late', $campaign === null ? 0 : ClosingProgress::late($campaign, $campaign->items()->getQuery(), $today)->count());
        }
        if ($this->context->allows('concerns.reply')) {
            $stale = (int) $this->settings->get('concerns.stale_days', $organizationId);
            $alerts[] = $this->alert('concerns_unanswered', Concern::query()
                ->where('level', ConcernLevel::MemberToCga)
                ->whereIn('status', [ConcernStatus::Open, ConcernStatus::InReview])
                ->where('awaiting_reply_since', '<=', $today->subDays($stale)->endOfDay())
                ->count());
        }

        return $alerts;
    }

    /**
     * @return array{code: string, count: int, label: string}
     */
    private function alert(string $code, int $count): array
    {
        return ['code' => $code, 'count' => $count, 'label' => trans_choice("statistics.alerts.{$code}", $count, ['count' => $count])];
    }

    private function computedAt(): ?string
    {
        $computed = StatOrgDaily::query()->max('computed_at');

        return $computed === null ? null : CarbonImmutable::parse((string) $computed)->toIso8601String();
    }
}
