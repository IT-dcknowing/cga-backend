<?php

namespace App\Domain\Trainings\Actions;

use App\Domain\Trainings\Enums\Attendance;
use App\Domain\Trainings\Enums\TrainingStatus;
use App\Domain\Trainings\Models\Training;
use App\Domain\Trainings\Models\TrainingRegistration;
use App\Domain\Trainings\Support\TrainingAccess;
use App\Support\Http\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * « Évaluer » la session (F-FOR-04, évaluation à chaud) : une personne présente à une session clôturée note
 * la formation de 1 à 5, avec un commentaire facultatif ; elle peut revenir sur son évaluation.
 */
class EvaluateTraining
{
    /**
     * @throws BusinessRuleException training_evaluation_closed
     */
    public function execute(TrainingRegistration $registration, int $score, ?string $comment): TrainingRegistration
    {
        return TrainingAccess::acrossCentres(static fn (): TrainingRegistration => DB::transaction(static function () use ($registration, $score, $comment): TrainingRegistration {
            $registration = TrainingRegistration::query()->lockForUpdate()->findOrFail($registration->id);
            $training = Training::query()->findOrFail($registration->training_id);
            if ($training->status !== TrainingStatus::Completed || $registration->attendance !== Attendance::Present) {
                throw new BusinessRuleException('training_evaluation_closed');
            }

            $registration->evaluation_score = $score;
            $registration->evaluation_comment = $comment;
            $registration->evaluated_at = CarbonImmutable::now();
            $registration->save();

            return $registration;
        }));
    }
}
