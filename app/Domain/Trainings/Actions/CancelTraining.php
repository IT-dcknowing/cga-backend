<?php

namespace App\Domain\Trainings\Actions;

use App\Domain\Trainings\Enums\RegistrationStatus;
use App\Domain\Trainings\Enums\TrainingStatus;
use App\Domain\Trainings\Models\Training;
use App\Domain\Trainings\Models\TrainingRegistration;
use App\Domain\Trainings\Support\TrainingAccess;
use App\Domain\Trainings\Support\TrainingNotifier;
use App\Support\Http\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * « Annuler la formation » (03-APPFLOW §3), avec un motif : un brouillon ou une formation publiée ; les
 * personnes inscrites, en attente ou en liste d'attente sont notifiées. Une session clôturée ne s'annule
 * plus.
 */
class CancelTraining
{
    public function __construct(private readonly TrainingNotifier $notifier) {}

    /**
     * @throws BusinessRuleException training_not_cancellable
     */
    public function execute(Training $training, string $reason): Training
    {
        return DB::transaction(function () use ($training, $reason): Training {
            $training = Training::query()->lockForUpdate()->findOrFail($training->id);
            if (! in_array($training->status, [TrainingStatus::Draft, TrainingStatus::Published], true)) {
                throw new BusinessRuleException('training_not_cancellable');
            }

            $training->status = TrainingStatus::Cancelled;
            $training->cancellation_reason = $reason;
            $training->cancelled_at = CarbonImmutable::now();
            $training->save();

            TrainingAccess::acrossCentres(fn () => TrainingAccess::registrations($training)
                ->whereIn('status', RegistrationStatus::active())
                ->get()
                ->each(fn (TrainingRegistration $registration) => $this->notifier->registration($registration, 'training_cancelled', ['reason' => $reason])));

            return $training;
        });
    }
}
