<?php

namespace App\Domain\Trainings\Actions;

use App\Domain\Trainings\Enums\RegistrationStatus;
use App\Domain\Trainings\Enums\TrainingStatus;
use App\Domain\Trainings\Models\Training;
use App\Domain\Trainings\Models\TrainingRegistration;
use App\Domain\Trainings\Support\TrainingAccess;
use App\Domain\Trainings\Support\TrainingNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Promotion automatique de la liste d'attente (RG-FOR-03) : tant qu'il reste des places d'une formation
 * publiée qui n'a pas commencé, la première personne en attente devient inscrite et en est notifiée. Après
 * un désistement, une hausse du nombre de places, et chaque quart d'heure (`trainings:waitlist`).
 */
class PromoteWaitlist
{
    public function __construct(private readonly TrainingNotifier $notifier) {}

    /**
     * @return int personnes promues
     */
    public function execute(Training $training): int
    {
        return TrainingAccess::acrossCentres(fn (): int => DB::transaction(function () use ($training): int {
            $training = Training::query()->lockForUpdate()->findOrFail($training->id);
            $now = CarbonImmutable::now();
            if ($training->status !== TrainingStatus::Published || $training->starts_at->lessThanOrEqualTo($now)) {
                return 0;
            }

            $left = TrainingAccess::seatsLeft($training);
            $waiting = TrainingRegistration::query()
                ->where('training_id', $training->id)
                ->where('status', RegistrationStatus::Waitlisted)
                ->orderBy('waitlist_position')
                ->when($left !== null, static fn ($query) => $query->limit((int) $left))
                ->get();

            foreach ($waiting as $registration) {
                $registration->status = RegistrationStatus::Registered;
                $registration->waitlist_position = null;
                $registration->promoted_at = $now;
                $registration->save();
                $this->notifier->registration($registration, 'promoted');
            }

            return $waiting->count();
        }));
    }

    /**
     * `trainings:waitlist` : toutes les formations publiées à venir qui ont une liste d'attente.
     *
     * @return int personnes promues
     */
    public function all(): int
    {
        $trainings = TrainingAccess::acrossCentres(static fn () => Training::query()
            ->where('status', TrainingStatus::Published)
            ->where('starts_at', '>', CarbonImmutable::now())
            ->whereHas('registrations', static fn ($registrations) => $registrations->where('status', RegistrationStatus::Waitlisted))
            ->get());

        return (int) $trainings->sum(fn (Training $training): int => $this->execute($training));
    }
}
