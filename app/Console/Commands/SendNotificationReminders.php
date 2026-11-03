<?php

namespace App\Console\Commands;

use App\Domain\Notifications\Actions\SendAgreementAlerts;
use App\Domain\Notifications\Actions\SendFollowUpReminders;
use App\Domain\Notifications\Actions\SendObligationReminders;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rappels quotidiens des notifications (02-TRD §11, PRD M13) : `reminders:obligations`,
 * `reminders:fees`, `reminders:documents`, `reminders:agreements` — une commande par tâche sur ce
 * même socle. Journalisée comme toute commande (SystemActivityListener) ; une erreur est
 * journalisée puis relancée.
 */
abstract class SendNotificationReminders extends Command
{
    /** Tâche : obligations, fees, documents ou agreements. */
    abstract protected function kind(): string;

    public function handle(SendObligationReminders $obligations, SendFollowUpReminders $followUps, SendAgreementAlerts $agreements): int
    {
        $today = CarbonImmutable::today();
        $kind = $this->kind();
        try {
            $result = match ($kind) {
                'obligations' => $obligations->execute($today),
                'fees' => ['fees' => $followUps->fees($today)],
                'documents' => ['documents' => $followUps->pieces($today)],
                'agreements' => $agreements->execute($today),
            };
        } catch (Throwable $exception) {
            Log::error("Rappels {$kind} : échec.", ['exception' => $exception]);

            throw $exception;
        }
        $this->info(collect($result)->map(static fn (int $count, string $key): string => "{$key} : {$count}")->implode(', '));

        return self::SUCCESS;
    }
}
