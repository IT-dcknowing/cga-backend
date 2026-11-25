<?php

namespace App\Domain\Trainings\Actions;

use App\Domain\Documents\Support\DocumentFormat;
use App\Domain\Trainings\Enums\RegistrationStatus;
use App\Domain\Trainings\Enums\TrainingStatus;
use App\Domain\Trainings\Models\TrainingRegistration;
use App\Domain\Trainings\Support\TrainingAccess;
use App\Domain\Trainings\Support\TrainingNotifier;
use App\Support\Config\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * `reminders:trainings` (PRD M13, « rappel J-1 ») : chaque inscrit d'une formation publiée qui commence
 * d'ici la fin du jour J+n (trainings.reminder_days) reçoit un rappel, une seule fois.
 */
class SendTrainingReminders
{
    public function __construct(
        private readonly TrainingNotifier $notifier,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @return int rappels envoyés
     */
    public function execute(CarbonImmutable $now): int
    {
        $until = $now->startOfDay()->addDays((int) $this->settings->get('trainings.reminder_days') + 1);

        return TrainingAccess::acrossCentres(function () use ($now, $until): int {
            $registrations = TrainingRegistration::query()
                ->with('training')
                ->where('status', RegistrationStatus::Registered)
                ->whereNull('reminded_at')
                ->whereHas('training', static fn (Builder $training) => $training
                    ->where('status', TrainingStatus::Published)
                    ->where('starts_at', '>', $now)
                    ->where('starts_at', '<', $until))
                ->get();

            foreach ($registrations as $registration) {
                $this->notifier->registration($registration, 'reminder', ['date' => DocumentFormat::dateTime($registration->training->starts_at)]);
                $registration->reminded_at = $now;
                $registration->save();
            }

            return $registrations->count();
        });
    }
}
