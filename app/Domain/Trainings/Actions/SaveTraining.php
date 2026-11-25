<?php

namespace App\Domain\Trainings\Actions;

use App\Domain\Trainings\Enums\AudienceType;
use App\Domain\Trainings\Enums\RegistrationStatus;
use App\Domain\Trainings\Enums\TrainingPaymentStatus;
use App\Domain\Trainings\Enums\TrainingStatus;
use App\Domain\Trainings\Models\Training;
use App\Domain\Trainings\Models\TrainingAudience;
use App\Domain\Trainings\Support\TrainingAccess;
use App\Domain\Trainings\Support\TrainingData;
use App\Domain\Users\Models\Membership;
use App\Support\Config\SettingsService;
use App\Support\Http\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Créer une formation en brouillon, ou la modifier tant qu'elle n'a pas commencé (F-FOR-01) : champs,
 * publics ciblés (un CGA ne cible que ses agents et adhérents, RG-FOR-01 : le périmètre vient de
 * l'organisateur, jamais de la requête). Le nombre de places ne descend pas sous le nombre d'inscrits ; une
 * hausse profite à la liste d'attente (RG-FOR-03) ; un changement de prix met à jour le statut de paiement
 * des inscriptions non réglées (RG-FOR-06).
 */
class SaveTraining
{
    public function __construct(
        private readonly PromoteWaitlist $promote,
        private readonly SettingsService $settings,
    ) {}

    public function create(TrainingData $data, Membership $actor): Training
    {
        return DB::transaction(function () use ($data, $actor): Training {
            $training = new Training;
            $training->organization_id = $actor->organization_id;
            $training->status = TrainingStatus::Draft;
            $training->created_by = $actor->user_id;
            $this->fill($training, $data);
            $training->save();
            $this->audiences($training, $data);

            return $training;
        });
    }

    /**
     * @throws BusinessRuleException training_locked, training_capacity_below_registered
     */
    public function update(Training $training, TrainingData $data): Training
    {
        $training = DB::transaction(function () use ($training, $data): Training {
            $training = Training::query()->lockForUpdate()->findOrFail($training->id);
            $started = $training->starts_at->lessThanOrEqualTo(CarbonImmutable::now());
            if (! in_array($training->status, [TrainingStatus::Draft, TrainingStatus::Published], true) || ($training->status === TrainingStatus::Published && $started)) {
                throw new BusinessRuleException('training_locked');
            }
            $taken = TrainingAccess::seatsTaken($training);
            if ($data->capacity !== null && $data->capacity < $taken) {
                throw new BusinessRuleException('training_capacity_below_registered', details: ['registered' => $taken]);
            }

            $this->fill($training, $data);
            $training->save();
            $this->audiences($training, $data);
            $this->payments($training);

            return $training;
        });
        $this->promote->execute($training);

        return $training->refresh();
    }

    private function fill(Training $training, TrainingData $data): void
    {
        $training->title = $data->title;
        $training->description = $data->description;
        $training->format = $data->format;
        $training->location = $data->location;
        $training->online_url = $data->onlineUrl;
        $training->trainer = $data->trainer;
        $training->starts_at = $data->startsAt;
        $training->ends_at = $data->endsAt;
        $training->capacity = $data->capacity;
        $training->price = $data->price;
        $training->requires_approval = $data->requiresApproval;
    }

    /** Publics remplacés par ceux saisis ; « nouveaux adhérents » avec l'ancienneté en vigueur. */
    private function audiences(Training $training, TrainingData $data): void
    {
        TrainingAudience::query()->where('training_id', $training->id)->get()->each->delete();
        foreach ($data->audiences as $audience) {
            $row = new TrainingAudience;
            $row->organization_id = $training->organization_id;
            $row->training_id = $training->id;
            $row->audience = $audience['type'];
            $row->regime = $audience['type'] === AudienceType::MembersByRegime ? $audience['regime'] : null;
            $row->new_member_months = $audience['type'] === AudienceType::NewMembers ? (int) $this->settings->get('trainings.new_member_months') : null;
            $row->save();
        }
    }

    private function payments(Training $training): void
    {
        TrainingAccess::acrossCentres(static function () use ($training): void {
            TrainingAccess::registrations($training)
                ->whereIn('status', RegistrationStatus::active())
                ->where('payment_status', '<>', TrainingPaymentStatus::Paid)
                ->get()
                ->each(static function ($registration) use ($training): void {
                    $registration->payment_status = $training->isPaid() ? TrainingPaymentStatus::ToPay : TrainingPaymentStatus::NotApplicable;
                    $registration->save();
                });
        });
    }
}
