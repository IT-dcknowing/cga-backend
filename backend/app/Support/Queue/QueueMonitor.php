<?php

namespace App\Support\Queue;

use App\Support\Config\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

/**
 * État des files d'attente de l'application (02-TRD §12.3) : pour chaque file surveillée (celle de la connexion
 * par défaut et queue-jobs.monitored_queues : documents, notifications, intégrations, imports), le nombre de
 * travaux en attente et l'âge, en minutes, du plus ancien travail disponible. Lu par le contrôle de santé
 * (alerte au-delà de queue.alert_delay_minutes) et par l'écran « Technique » de /plateforme. Aucune donnée
 * des travaux n'est lue.
 */
class QueueMonitor
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @return list<string>
     */
    public function queues(): array
    {
        $connection = (string) config('queue.default');

        return array_values(array_unique(array_filter([
            (string) config("queue.connections.{$connection}.queue", 'default'),
            ...array_map('strval', (array) config('queue-jobs.monitored_queues')),
        ], static fn (string $queue): bool => $queue !== '')));
    }

    public function thresholdMinutes(): int
    {
        return (int) $this->settings->get('queue.alert_delay_minutes');
    }

    /**
     * @return list<array{queue: string, pending: int, delay_minutes: int|null, delayed: bool}>
     */
    public function snapshot(): array
    {
        $connection = Queue::connection((string) config('queue.default'));
        $now = CarbonImmutable::now()->getTimestamp();
        $threshold = $this->thresholdMinutes();

        $states = [];
        foreach ($this->queues() as $queue) {
            $pending = $connection->size($queue);
            $oldest = $pending > 0 ? $connection->creationTimeOfOldestPendingJob($queue) : null;
            $delay = is_numeric($oldest) ? max(0, (int) floor(($now - (int) $oldest) / 60)) : null;
            $states[] = [
                'queue' => $queue,
                'pending' => $pending,
                'delay_minutes' => $delay,
                'delayed' => $delay !== null && $delay >= $threshold,
            ];
        }

        return $states;
    }
}
