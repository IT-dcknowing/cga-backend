<?php

namespace App\Support\Logging;

use App\Domain\Audit\Enums\ActivityResult;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

/**
 * Journal d'activité hors requêtes HTTP (02-TRD §6.5) : chaque Job (system:queue), chaque tâche
 * planifiée (system:scheduler) et chaque commande (system:console), avec son résultat et sa durée.
 */
class SystemActivityListener
{
    /**
     * @var array<string, int> départ (hrtime) par Job ou commande en cours
     */
    private array $startedAt = [];

    public function __construct(private readonly Repository $config) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(JobProcessing::class, fn (JobProcessing $event) => $this->start('job:'.$event->job->getJobId()));
        $events->listen(JobProcessed::class, fn (JobProcessed $event) => $this->job($event->job->resolveName(), $event->job->getJobId(), ActivityResult::Success));
        $events->listen(JobFailed::class, fn (JobFailed $event) => $this->job($event->job->resolveName(), $event->job->getJobId(), ActivityResult::Error, $event->exception::class));
        $events->listen(ScheduledTaskFinished::class, fn (ScheduledTaskFinished $event) => $this->task($event->task, ActivityResult::Success, (int) round($event->runtime * 1000)));
        $events->listen(ScheduledTaskFailed::class, fn (ScheduledTaskFailed $event) => $this->task($event->task, ActivityResult::Error, null, $event->exception::class));
        $events->listen(CommandStarting::class, fn (CommandStarting $event) => $this->start('command:'.$event->command));
        $events->listen(CommandFinished::class, fn (CommandFinished $event) => $this->command((string) $event->command, $event->exitCode));
    }

    private function start(string $key): void
    {
        $this->startedAt[$key] = hrtime(true);
    }

    private function job(string $class, ?string $jobId, ActivityResult $result, ?string $error = null): void
    {
        app(ActivityLogger::class)->record('job.'.class_basename($class), $result, [
            'actor_type' => 'system',
            'actor_label' => 'system:queue',
            'params' => ['job_id' => $jobId],
            'duration_ms' => $this->duration('job:'.$jobId),
            'error_code' => $error === null ? null : class_basename($error),
        ]);
    }

    private function task(ScheduledEvent $task, ActivityResult $result, ?int $durationMs, ?string $error = null): void
    {
        app(ActivityLogger::class)->record('schedule.'.$this->taskName($task), $result, [
            'actor_type' => 'system',
            'actor_label' => 'system:scheduler',
            'duration_ms' => $durationMs,
            'error_code' => $error === null ? null : class_basename($error),
        ]);
    }

    private function command(string $name, int $exitCode): void
    {
        if ($name === '' || in_array($name, $this->config->array('activity.ignored_commands'), true)) {
            unset($this->startedAt['command:'.$name]);

            return;
        }

        app(ActivityLogger::class)->record('command.'.$name, $exitCode === 0 ? ActivityResult::Success : ActivityResult::Error, [
            'actor_type' => 'system',
            'actor_label' => 'system:console',
            'duration_ms' => $this->duration('command:'.$name),
            'error_code' => $exitCode === 0 ? null : "exit_{$exitCode}",
        ]);
    }

    /**
     * Nom de la commande Artisan d'une tâche planifiée (« … artisan logs:partitions » → logs:partitions).
     */
    private function taskName(ScheduledEvent $task): string
    {
        $command = (string) $task->command;

        return preg_match('/artisan[\'"]?\s+([^\s\'"]+)/', $command, $match) === 1
            ? $match[1]
            : ($task->description ?? $command);
    }

    private function duration(string $key): ?int
    {
        $started = $this->startedAt[$key] ?? null;
        unset($this->startedAt[$key]);

        return $started === null ? null : intdiv(hrtime(true) - $started, 1_000_000);
    }
}
