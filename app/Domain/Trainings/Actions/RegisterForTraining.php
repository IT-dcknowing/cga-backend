<?php

namespace App\Domain\Trainings\Actions;

use App\Domain\Trainings\Enums\RegistrationStatus;
use App\Domain\Trainings\Enums\TrainingPaymentStatus;
use App\Domain\Trainings\Enums\TrainingStatus;
use App\Domain\Trainings\Models\Training;
use App\Domain\Trainings\Models\TrainingRegistration;
use App\Domain\Trainings\Support\TrainingAccess;
use App\Domain\Trainings\Support\TrainingAudiences;
use App\Domain\Trainings\Support\TrainingNotifier;
use App\Domain\Users\Models\Membership;
use App\Support\Http\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * S'inscrire ou inscrire des personnes ciblées à une formation publiée qui n'a pas commencé (F-FOR-02) :
 * - avec validation par l'organisateur : « en attente de validation » (RG-FOR-05) ;
 * - sinon inscrite tant qu'il reste des places, puis en liste d'attente, dans l'ordre (RG-FOR-03) ;
 * - formation payante : « à payer » (RG-FOR-06).
 * Une personne n'est inscrite qu'une fois (hors annulation) ; chacune est notifiée de son statut. La
 * formation est verrouillée le temps de l'attribution des places.
 */
class RegisterForTraining
{
    public function __construct(
        private readonly TrainingAudiences $audiences,
        private readonly TrainingNotifier $notifier,
    ) {}

    /**
     * @param  list<Membership>  $people
     * @return array{registered: int, waitlisted: int, pending: int, already: int}
     *
     * @throws BusinessRuleException training_registration_closed, training_not_targeted
     */
    public function execute(Training $training, array $people, Membership $actor): array
    {
        return TrainingAccess::acrossCentres(fn (): array => DB::transaction(function () use ($training, $people, $actor): array {
            $training = self::lockOpen($training->id);
            $outside = array_values(array_filter($people, fn (Membership $person): bool => ! $this->audiences->targets($training, $person)));
            if ($outside !== []) {
                throw new BusinessRuleException('training_not_targeted', details: ['memberships' => array_map(static fn (Membership $person): string => $person->id, $outside)]);
            }

            $counts = ['registered' => 0, 'waitlisted' => 0, 'pending' => 0, 'already' => 0];
            $left = TrainingAccess::seatsLeft($training);
            foreach ($people as $person) {
                if (TrainingRegistration::query()->where('training_id', $training->id)->where('user_id', $person->user_id)->where('status', '<>', RegistrationStatus::Cancelled)->exists()) {
                    $counts['already']++;

                    continue;
                }

                $registration = new TrainingRegistration;
                $registration->organization_id = $person->organization_id;
                $registration->training_id = $training->id;
                $registration->membership_id = $person->id;
                $registration->user_id = $person->user_id;
                $registration->member_id = $person->member_id;
                $registration->registered_by = $actor->user_id;
                $registration->payment_status = $training->isPaid() ? TrainingPaymentStatus::ToPay : TrainingPaymentStatus::NotApplicable;
                if ($training->requires_approval) {
                    $registration->status = RegistrationStatus::PendingApproval;
                } elseif ($left === null || $left > 0) {
                    $registration->status = RegistrationStatus::Registered;
                    $left = $left === null ? null : $left - 1;
                } else {
                    self::waitlist($registration);
                }
                $registration->save();

                $event = match ($registration->status) {
                    RegistrationStatus::PendingApproval => 'pending',
                    RegistrationStatus::Waitlisted => 'waitlisted',
                    default => 'registered',
                };
                $counts[$event]++;
                $this->notifier->registration($registration, $event, ['position' => (int) TrainingAccess::waitlistRank($registration)]);
            }

            return $counts;
        }));
    }

    /**
     * Inscription de la personne de l'espace actif.
     *
     * @throws BusinessRuleException training_already_registered, training_registration_closed, training_not_targeted
     */
    public function self(Training $training, Membership $person): TrainingRegistration
    {
        if ($this->execute($training, [$person], $person)['already'] > 0) {
            throw new BusinessRuleException('training_already_registered');
        }

        return TrainingAccess::acrossCentres(static fn (): TrainingRegistration => TrainingRegistration::query()
            ->where('training_id', $training->id)->where('user_id', $person->user_id)->where('status', '<>', RegistrationStatus::Cancelled)->firstOrFail());
    }

    /**
     * Formation verrouillée, ouverte aux inscriptions : publiée et pas encore commencée.
     *
     * @throws BusinessRuleException training_registration_closed
     */
    public static function lockOpen(string $trainingId): Training
    {
        $training = Training::query()->with('organization')->lockForUpdate()->findOrFail($trainingId);
        if ($training->status !== TrainingStatus::Published || $training->starts_at->lessThanOrEqualTo(CarbonImmutable::now())) {
            throw new BusinessRuleException('training_registration_closed');
        }

        return $training;
    }

    /** En fin de liste d'attente. */
    public static function waitlist(TrainingRegistration $registration): void
    {
        $last = TrainingRegistration::query()->where('training_id', $registration->training_id)->max('waitlist_position');
        $registration->status = RegistrationStatus::Waitlisted;
        $registration->waitlist_position = (int) $last + 1;
    }
}
