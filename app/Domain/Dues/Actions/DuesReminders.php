<?php

namespace App\Domain\Dues\Actions;

use App\Domain\Documents\Support\DocumentFormat;
use App\Domain\Dues\Enums\DuesStatus;
use App\Domain\Dues\Models\NetworkDue;
use App\Domain\Dues\Support\DuesRules;
use App\Domain\Notifications\Support\Recipients;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\Role;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Relances de cotisation (F-COT-05) :
 * - RG-COT-05 : `reminders:dues` chaque jour — statut recalculé (RG-COT-04), relance au gérant aux jours
 *   paramétrés après la date butoir (dues.reminder_offsets : J+30, J+60, J+90) ; relance manuelle à tout
 *   moment depuis /cncga/cotisations ;
 * - RG-COT-06 : à J+90 (dues.alert_offset), la Présidente est alertée qu'elle peut suspendre le CGA —
 *   la suspension n'est jamais automatique (CR-04).
 * Une relance est datée sur la cotisation : relancer la tâche le même jour ne renvoie rien.
 */
class DuesReminders
{
    public function __construct(
        private readonly Recipients $recipients,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @return array{reminders: int, alerts: int}
     */
    public function daily(CarbonImmutable $today): array
    {
        return Tenancy::bypass(function () use ($today): array {
            $totals = ['reminders' => 0, 'alerts' => 0];
            $offsets = array_map('intval', (array) $this->settings->get('dues.reminder_offsets'));
            $alert = (int) $this->settings->get('dues.alert_offset');
            NetworkDue::query()->whereIn('status', [DuesStatus::Due, DuesStatus::Partial, DuesStatus::Unpaid])->orderBy('id')->each(function (NetworkDue $due) use ($today, $offsets, $alert, &$totals): void {
                $deadline = CarbonImmutable::parse($due->deadline);
                $status = DuesRules::status($due->amount_due, $due->amount_paid, $deadline, $today);
                if ($status !== $due->status) {
                    $due->status = $status;
                    $due->save();
                }
                $days = (int) $deadline->startOfDay()->diffInDays($today, false);
                if ($days <= 0 || $due->last_reminder_at?->isSameDay($today)) {
                    return;
                }
                if (in_array($days, $offsets, true)) {
                    $this->remind($due, $today);
                    $totals['reminders']++;
                }
                if ($days === $alert) {
                    $this->recipients->notify($this->recipients->network('organizations.suspend'), 'dues_alert', [
                        'cga' => $this->cgaName($due), 'year' => $due->year, 'days' => $days, 'amount' => DocumentFormat::amount($due->balance()),
                    ], "/cncga/cga/{$due->organization_id}");
                    $totals['alerts']++;
                }
            });

            return $totals;
        }, 'Tâche reminders:dues : relances de cotisation de tous les CGA (RG-COT-05, 06).');
    }

    /** Relance au gérant du CGA (automatique ou manuelle), datée et comptée sur la cotisation. */
    public function remind(NetworkDue $due, CarbonImmutable $now): NetworkDue
    {
        return DB::transaction(function () use ($due, $now): NetworkDue {
            $due = Tenancy::bypass(static fn (): NetworkDue => NetworkDue::query()->lockForUpdate()->findOrFail($due->id), 'Cotisation : relance.');
            $this->recipients->notify($this->recipients->roles($due->organization_id, [Role::CgaManager]), 'dues_reminder', [
                'year' => $due->year,
                'amount' => DocumentFormat::amount($due->balance()),
                'days' => max(0, (int) CarbonImmutable::parse($due->deadline)->startOfDay()->diffInDays($now->startOfDay(), false)),
                'date' => DocumentFormat::date(CarbonImmutable::parse($due->deadline)),
            ], '/cga/cotisation');
            $due->last_reminder_at = $now;
            $due->reminder_count++;
            $due->save();

            return $due;
        });
    }

    private function cgaName(NetworkDue $due): string
    {
        return (string) Organization::query()->whereKey($due->organization_id)->value('name');
    }
}
