<?php

namespace App\Domain\Trainings\Support;

use App\Domain\Trainings\Enums\Attendance;
use App\Domain\Trainings\Models\Training;
use App\Domain\Trainings\Models\TrainingRegistration;

/**
 * Résultats agrégés de l'évaluation à chaud (F-FOR-04) : présents, réponses, note moyenne, répartition des
 * notes, commentaires sans le nom de leur auteur.
 */
final class TrainingEvaluations
{
    private const SCALE = [1, 2, 3, 4, 5];

    private const ROUNDING = 1;

    /**
     * @return array<string, mixed>
     */
    public static function summary(Training $training): array
    {
        $present = TrainingAccess::acrossCentres(static fn () => TrainingAccess::registrations($training)->where('attendance', Attendance::Present)->get());
        $answers = $present->whereNotNull('evaluated_at');

        return [
            'present' => $present->count(),
            'answers' => $answers->count(),
            'average' => $answers->isEmpty() ? null : round((float) $answers->avg('evaluation_score'), self::ROUNDING),
            'distribution' => collect(self::SCALE)->mapWithKeys(static fn (int $score): array => [$score => $answers->where('evaluation_score', $score)->count()])->all(),
            'comments' => $answers
                ->filter(static fn (TrainingRegistration $answer): bool => $answer->evaluation_comment !== null)
                ->sortByDesc('evaluated_at')
                ->map(static fn (TrainingRegistration $answer): array => ['score' => $answer->evaluation_score, 'comment' => $answer->evaluation_comment])
                ->values()
                ->all(),
        ];
    }
}
