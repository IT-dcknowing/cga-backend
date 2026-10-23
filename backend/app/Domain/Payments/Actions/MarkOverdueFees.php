<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Enums\ChargeKind;
use App\Domain\Payments\Enums\ChargeStatus;
use App\Domain\Payments\Models\MemberCharge;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Passage en « impayée » (RG-PAY-03, tâche statuses:refresh chaque nuit) : une échéance d'honoraires
 * non réglée fees.overdue_days jours après sa date (30 par défaut, réglable par CGA) devient impayée,
 * depuis ce jour-là. Le statut seul change : aucun blocage, aucune suspension (CR-04) ; les rappels
 * partent avec le moteur de notifications (Lot 6). Idempotente, tous les CGA (contournement journalisé).
 */
class MarkOverdueFees
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @return int nombre d'échéances passées impayées
     */
    public function execute(?CarbonImmutable $day = null): int
    {
        $day ??= CarbonImmutable::today();

        return Tenancy::bypass(fn (): int => DB::transaction(function () use ($day): int {
            $charges = MemberCharge::query()
                ->where('kind', ChargeKind::Fees)
                ->whereIn('status', [ChargeStatus::Due, ChargeStatus::Partial])
                ->whereDate('due_date', '<', $day)
                ->orderBy('due_date')
                ->lockForUpdate()
                ->get();

            $delays = [];
            $marked = 0;
            foreach ($charges as $charge) {
                $delays[$charge->organization_id] ??= (int) $this->settings->get('fees.overdue_days', $charge->organization_id);
                $since = $charge->due_date->addDays($delays[$charge->organization_id]);
                if ($since->gt($day)) {
                    continue;
                }

                $charge->status = ChargeStatus::Overdue;
                $charge->overdue_since = $since;
                $charge->save();
                $marked++;
            }

            return $marked;
        }), 'Tâche statuses:refresh : échéances d\'honoraires impayées dans tous les CGA (RG-PAY-03).');
    }
}
