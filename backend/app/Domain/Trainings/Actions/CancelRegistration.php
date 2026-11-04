<?php

namespace App\Domain\Trainings\Actions;

use App\Domain\Trainings\Enums\RegistrationStatus;
use App\Domain\Trainings\Enums\TrainingStatus;
use App\Domain\Trainings\Models\Training;
use App\Domain\Trainings\Models\TrainingRegistration;
use App\Domain\Trainings\Support\TrainingAccess;
use App\Domain\Trainings\Support\TrainingNotifier;
use App\Domain\Users\Models\Membership;
use App\Support\Config\SettingsService;
use App\Support\Http\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Se désinscrire, ou désinscrire une personne de son centre (F-FOR-02) : possible jusqu'à J-n avant la
 * session (RG-FOR-04, trainings.cancellation_days). Une place libérée revient à la liste d'attente
 * (RG-FOR-03). La personne est notifiée quand un autre l'a désinscrite.
 */
class CancelRegistration
{
    public function __construct(
        private readonly PromoteWaitlist $promote,
        private readonly TrainingNotifier $notifier,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @throws BusinessRuleException training_registration_not_active, training_cancellation_closed
     */
    public function execute(TrainingRegistration $registration, Membership $actor): TrainingRegistration
    {
        return TrainingAccess::acrossCentres(fn (): TrainingRegistration => DB::transaction(function () use ($registration, $actor): TrainingRegistration {
            $training = Training::query()->lockForUpdate()->findOrFail($registration->training_id);
            $registration = TrainingRegistration::query()->lockForUpdate()->findOrFail($registration->id);
            if (! $registration->status->isActive() || $training->status !== TrainingStatus::Published) {
                throw new BusinessRuleException('training_registration_not_active');
            }

            $days = (int) $this->settings->get('trainings.cancellation_days');
            $now = CarbonImmutable::now();
            if ($now->greaterThan($training->starts_at->subDays($days))) {
                throw new BusinessRuleException('training_cancellation_closed', details: ['days' => $days]);
            }

            $freed = $registration->status === RegistrationStatus::Registered;
            $registration->status = RegistrationStatus::Cancelled;
            $registration->waitlist_position = null;
            $registration->cancelled_at = $now;
            $registration->save();

            if ($actor->user_id !== $registration->user_id) {
                $this->notifier->registration($registration, 'cancelled');
            }
            if ($freed) {
                $this->promote->execute($training);
            }

            return $registration;
        }));
    }
}
