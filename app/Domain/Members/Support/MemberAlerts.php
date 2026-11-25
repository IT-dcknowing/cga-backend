<?php

namespace App\Domain\Members\Support;

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Models\Member;
use App\Domain\Payments\Models\MemberCharge;
use App\Support\Config\SettingsService;
use Illuminate\Support\Collection;

/**
 * Alertes d'un dossier, montrées en bandeau sur la fiche (03-APPFLOW §4) ; une alerte ne bloque rien
 * (CR-04), le gérant décide :
 * - « Radiation requise — reclassement au régime réel » tant qu'un dossier actif du portefeuille CGA
 *   reste au régime réel sans radiation demandée (RG-ADH-08), en retard passé
 *   rsi.radiation_deadline_days (RG-ADH-09) ;
 * - « Honoraires impayés » dès qu'une échéance d'honoraires est impayée (RG-PAY-03), avec le nombre de
 *   périodes impayées (RG-PAY-04), en retard passé fees.alert_after_months depuis la plus ancienne
 *   (RG-PAY-05).
 * Une alerte en retard peut motiver un blocage manuel de l'attestation (RG-DOC-10).
 */
class MemberAlerts
{
    public const RADIATION_REQUIRED = 'radiation_required';

    public const FEES_OVERDUE = 'fees_overdue';

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @return list<array{code: string, label: string, since: string, days: int, overdue: bool, periods?: int}>
     */
    public function for(Member $member): array
    {
        return array_values(array_filter([$this->radiationRequired($member), $this->feesOverdue($member)]));
    }

    /**
     * L'alerte est présente et en retard.
     */
    public function overdue(Member $member, string $code): bool
    {
        foreach ($this->for($member) as $alert) {
            if ($alert['code'] === $code) {
                return $alert['overdue'];
            }
        }

        return false;
    }

    /**
     * Nombre de périodes d'honoraires impayées : « Impayé (n mois) » (RG-PAY-04).
     */
    public function overdueFeePeriods(Member $member): int
    {
        return $this->overdueFees($member)->count();
    }

    /**
     * @return array{code: string, label: string, since: string, days: int, overdue: bool}|null
     */
    private function radiationRequired(Member $member): ?array
    {
        if ($member->portfolio !== Portfolio::Cga || $member->status !== MemberStatus::Active || $member->rsi_since === null) {
            return null;
        }
        $days = max(0, (int) $member->rsi_since->diffInDays(today()));
        $deadline = (int) $this->settings->get('rsi.radiation_deadline_days', $member->organization_id);

        return [
            'code' => self::RADIATION_REQUIRED,
            'label' => (string) __('members.alerts.radiation_required'),
            'since' => $member->rsi_since->toDateString(),
            'days' => $days,
            'overdue' => $days >= $deadline,
        ];
    }

    /**
     * @return array{code: string, label: string, since: string, days: int, overdue: bool, periods: int}|null
     */
    private function feesOverdue(Member $member): ?array
    {
        $overdue = $this->overdueFees($member);
        $oldest = $overdue->sortBy('due_date')->first()?->due_date;
        if ($oldest === null) {
            return null;
        }
        $months = (int) $this->settings->get('fees.alert_after_months', $member->organization_id);

        return [
            'code' => self::FEES_OVERDUE,
            'label' => (string) __('members.alerts.fees_overdue', ['periods' => $overdue->count()]),
            'since' => $oldest->toDateString(),
            'days' => max(0, (int) $oldest->diffInDays(today())),
            'overdue' => $oldest->addMonthsNoOverflow($months)->lt(today()),
            'periods' => $overdue->count(),
        ];
    }

    /**
     * @return Collection<int, MemberCharge>
     */
    private function overdueFees(Member $member): Collection
    {
        return $member->relationLoaded('overdueFees') ? $member->overdueFees : $member->overdueFees()->get();
    }
}
