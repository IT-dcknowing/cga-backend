<?php

namespace App\Domain\Trainings\Actions;

use App\Domain\Trainings\Enums\RegistrationStatus;
use App\Domain\Trainings\Models\Training;
use App\Domain\Trainings\Models\TrainingRegistration;
use App\Domain\Trainings\Support\TrainingAccess;
use App\Domain\Trainings\Support\TrainingNotifier;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * « Valider » ou « Refuser » une inscription en attente de validation (RG-FOR-05), par l'organisateur,
 * avant la session : validée, elle prend une place libre ou rejoint la liste d'attente (RG-FOR-03) ;
 * refusée, avec un motif facultatif. La personne est notifiée.
 */
class ReviewRegistration
{
    public function __construct(private readonly TrainingNotifier $notifier) {}

    /**
     * @throws BusinessRuleException training_registration_not_pending, training_registration_closed
     */
    public function approve(TrainingRegistration $registration): TrainingRegistration
    {
        return $this->review($registration, function (Training $training, TrainingRegistration $registration): string {
            $left = TrainingAccess::seatsLeft($training);
            if ($left === null || $left > 0) {
                $registration->status = RegistrationStatus::Registered;

                return 'approved';
            }
            RegisterForTraining::waitlist($registration);

            return 'waitlisted';
        });
    }

    /**
     * @throws BusinessRuleException training_registration_not_pending, training_registration_closed
     */
    public function reject(TrainingRegistration $registration, ?string $reason): TrainingRegistration
    {
        return $this->review($registration, static function (Training $training, TrainingRegistration $registration) use ($reason): string {
            $registration->status = RegistrationStatus::Rejected;
            $registration->rejection_reason = $reason;

            return 'rejected';
        });
    }

    /**
     * @param  callable(Training, TrainingRegistration): string  $decide  événement notifié
     */
    private function review(TrainingRegistration $registration, callable $decide): TrainingRegistration
    {
        return TrainingAccess::acrossCentres(fn (): TrainingRegistration => DB::transaction(function () use ($registration, $decide): TrainingRegistration {
            $training = RegisterForTraining::lockOpen($registration->training_id);
            $registration = TrainingRegistration::query()->lockForUpdate()->findOrFail($registration->id);
            if ($registration->status !== RegistrationStatus::PendingApproval) {
                throw new BusinessRuleException('training_registration_not_pending');
            }

            $event = $decide($training, $registration);
            $registration->save();
            $this->notifier->registration($registration, $event, ['position' => (int) TrainingAccess::waitlistRank($registration)]);

            return $registration;
        }));
    }
}
