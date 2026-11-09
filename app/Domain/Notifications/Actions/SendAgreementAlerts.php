<?php

namespace App\Domain\Notifications\Actions;

use App\Domain\Documents\Support\DocumentFormat;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Members\Models\Member;
use App\Domain\Notifications\Support\Recipients;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Payments\Enums\ChargeStatus;
use App\Domain\Payments\Models\MemberCharge;
use App\Domain\Users\Enums\Role;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * `reminders:agreements` chaque jour (02-TRD §11, PRD M13) — alertes seulement, aucune décision
 * automatique (CR-04, RG-ADH-18) :
 * - agrément d'un CGA arrivant à échéance dans 6 puis 3 mois (agreements.alert_months, RG-ORG-10), au
 *   gérant et au CNCGA ;
 * - dossier RSI ou RNI resté au portefeuille CGA à J+60 et J+90 (rsi.alert_days), aux agents qui
 *   demandent les radiations ;
 * - honoraires impayés depuis plus de fees.alert_after_months mois (RG-PAY-05), au gérant.
 * Chaque alerte part une fois (le jour dit, mémorisée).
 */
class SendAgreementAlerts
{
    public function __construct(
        private readonly Recipients $recipients,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @return array{agreements: int, rsi: int, fees: int}
     */
    public function execute(CarbonImmutable $today): array
    {
        return Tenancy::bypass(fn (): array => [
            'agreements' => $this->agreements($today),
            'rsi' => $this->rsi($today),
            'fees' => $this->fees($today),
        ], 'Tâche reminders:agreements : alertes d\'agrément, de régime et d\'impayés de tous les CGA (PRD M13).');
    }

    private function agreements(CarbonImmutable $today): int
    {
        $sent = 0;
        $months = array_map('intval', (array) $this->settings->get('agreements.alert_months'));
        Organization::query()->where('type', OrganizationType::Cga)->where('status', '<>', OrganizationStatus::Terminated)->whereNotNull('agreement_expires_at')->orderBy('id')->each(
            function (Organization $cga) use ($today, $months, &$sent): void {
                $expires = CarbonImmutable::parse($cga->agreement_expires_at);
                foreach ($months as $before) {
                    if (! $today->isSameDay($expires->subMonthsNoOverflow($before)) || ! $this->once("agreement:{$cga->id}:{$before}:{$expires->toDateString()}")) {
                        continue;
                    }
                    $values = ['cga' => $cga->name, 'date' => DocumentFormat::date($expires), 'months' => $before];
                    $this->recipients->notify($this->recipients->roles($cga->id, [Role::CgaManager]), 'agreement_expiring', $values, '/cga/parametres');
                    $this->recipients->notify($this->recipients->network('organizations.view'), 'agreement_expiring', $values, "/cncga/cga/{$cga->id}");
                    $sent++;
                }
            },
        );

        return $sent;
    }

    private function rsi(CarbonImmutable $today): int
    {
        $sent = 0;
        Member::query()->regulatory()->whereIn('status', [MemberStatus::Active, MemberStatus::RadiationPending])->whereIn('regime', [TaxRegime::Rsi, TaxRegime::Rni])->whereNotNull('rsi_since')->orderBy('id')->each(
            function (Member $member) use ($today, &$sent): void {
                $days = (int) CarbonImmutable::parse($member->rsi_since)->startOfDay()->diffInDays($today);
                $alerts = array_map('intval', (array) $this->settings->get('rsi.alert_days', $member->organization_id));
                if (! in_array($days, $alerts, true) || ! $this->once("rsi:{$member->id}:{$days}")) {
                    return;
                }
                $this->recipients->notify($this->recipients->agents($member->organization_id, 'members.radiation.request'), 'rsi_not_radiated', ['member' => $member->company_name, 'days' => $days], "/cga/adherents/{$member->id}");
                $sent++;
            },
        );

        return $sent;
    }

    private function fees(CarbonImmutable $today): int
    {
        $sent = 0;
        MemberCharge::query()->where('status', ChargeStatus::Overdue)->whereNotNull('overdue_since')->get()->groupBy('member_id')->each(
            function ($charges, string $memberId) use ($today, &$sent): void {
                $oldest = CarbonImmutable::parse((string) $charges->min('overdue_since'));
                $member = Member::query()->find($memberId);
                if ($member === null || ! in_array($member->status, [MemberStatus::Active, MemberStatus::RadiationPending], true)) {
                    return;
                }
                $months = (int) $this->settings->get('fees.alert_after_months', $member->organization_id);
                if (! $today->isSameDay($oldest->addMonthsNoOverflow($months)) || ! $this->once("fees:{$memberId}:{$oldest->toDateString()}")) {
                    return;
                }
                $this->recipients->notify($this->recipients->roles($member->organization_id, [Role::CgaManager]), 'fees_unpaid_alert', ['member' => $member->company_name, 'months' => $months], "/cga/adherents/{$memberId}");
                $sent++;
            },
        );

        return $sent;
    }

    private function once(string $key): bool
    {
        return Cache::add("reminders:agreements:{$key}", true, now()->addDays((int) config('reports.reminder_memory_days')));
    }
}
