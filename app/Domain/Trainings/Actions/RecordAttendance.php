<?php

namespace App\Domain\Trainings\Actions;

use App\Domain\Trainings\Enums\Attendance;
use App\Domain\Trainings\Enums\RegistrationStatus;
use App\Domain\Trainings\Enums\TrainingStatus;
use App\Domain\Trainings\Models\Training;
use App\Domain\Trainings\Support\TrainingAccess;
use App\Support\Http\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Liste de présence (F-FOR-04) : à partir du début de la session et jusqu'à sa clôture, l'organisateur
 * coche chaque inscrit présent ou absent.
 */
class RecordAttendance
{
    /**
     * @param  array<string, Attendance>  $marks  inscription => présence
     * @return int inscriptions émargées
     *
     * @throws BusinessRuleException training_not_in_session
     * @throws NotFoundHttpException inscription qui n'est pas celle d'un inscrit de la formation
     */
    public function execute(Training $training, array $marks): int
    {
        return TrainingAccess::acrossCentres(fn (): int => DB::transaction(function () use ($training, $marks): int {
            $training = Training::query()->lockForUpdate()->findOrFail($training->id);
            if ($training->status !== TrainingStatus::Published || $training->starts_at->greaterThan(CarbonImmutable::now())) {
                throw new BusinessRuleException('training_not_in_session');
            }

            foreach ($marks as $registrationId => $attendance) {
                $registration = TrainingAccess::registration($training, $registrationId);
                if ($registration->status !== RegistrationStatus::Registered) {
                    throw new NotFoundHttpException;
                }
                $registration->attendance = $attendance;
                $registration->save();
            }

            return count($marks);
        }));
    }
}
