<?php

namespace App\Domain\Closing\Actions;

use App\Domain\Closing\Enums\ClosingCampaignStatus;
use App\Domain\Closing\Enums\ClosingStep;
use App\Domain\Closing\Events\ClosingPiecesReminderDue;
use App\Domain\Closing\Events\ClosingStepsLate;
use App\Domain\Closing\Models\ClosingCampaign;
use App\Domain\Closing\Models\ClosingCampaignItem;
use App\Domain\Closing\Support\ClosingReminders;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * `reminders:closing` chaque jour (02-TRD §11, RG-OBL-11) : dans chaque campagne ouverte, les rappels
 * de pièces dus aujourd'hui aux clients et le relevé des dossiers en retard pour les agents ; les
 * événements sont remis au moteur de notifications (Lot 6). Rien n'est envoyé deux fois le même jour :
 * les dates des rappels sont calculées, sans compteur.
 */
class SendClosingReminders
{
    private const CHUNK = 200;

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @return array{pieces: int, late: int}
     */
    public function execute(CarbonImmutable $today): array
    {
        return Tenancy::bypass(function () use ($today): array {
            $totals = ['pieces' => 0, 'late' => 0];
            ClosingCampaign::query()->where('status', ClosingCampaignStatus::Open)->orderBy('id')->each(function (ClosingCampaign $campaign) use ($today, &$totals): void {
                /** @var list<int> $offsets */
                $offsets = array_map('intval', (array) $this->settings->get('closing.reminder_offsets', $campaign->organization_id));
                $every = (int) $this->settings->get('closing.late_reminder_every_days', $campaign->organization_id);
                $due = $campaign->due(ClosingStep::Pieces);
                $late = [];

                $campaign->items()->chunkById(self::CHUNK, function (Collection $items) use ($campaign, $today, $offsets, $every, $due, &$late, &$totals): void {
                    foreach ($items as $item) {
                        /** @var ClosingCampaignItem $item */
                        $reason = ClosingReminders::piecesReminder($due, $item->doneOn(ClosingStep::Pieces), $today, $offsets, $every);
                        if ($reason !== null) {
                            ClosingPiecesReminderDue::dispatch($item, $reason);
                            $totals['pieces']++;
                        }
                        $step = ClosingReminders::lateStep($campaign, $item, $today);
                        if ($step !== null) {
                            $late[$step->value] = ($late[$step->value] ?? 0) + 1;
                        }
                    }
                });

                if ($late !== []) {
                    ClosingStepsLate::dispatch($campaign, $late);
                    $totals['late'] += array_sum($late);
                }
            });

            return $totals;
        }, 'Tâche reminders:closing : rappels de la campagne de clôture dans tous les CGA (RG-OBL-11).');
    }
}
