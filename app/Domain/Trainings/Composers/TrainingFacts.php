<?php

namespace App\Domain\Trainings\Composers;

use App\Domain\Documents\Support\DocumentFormat;
use App\Domain\Trainings\Models\Training;

/**
 * Données d'une formation communes à ses documents (attestation, reçu).
 */
final class TrainingFacts
{
    private const MINUTES_PER_HOUR = 60;

    /**
     * @return array<string, string>
     */
    public static function values(Training $training): array
    {
        $minutes = (int) $training->starts_at->diffInMinutes($training->ends_at);

        return [
            'training_title' => $training->title,
            'training_dates' => (string) __('trainings.dates', ['start' => DocumentFormat::dateTime($training->starts_at), 'end' => DocumentFormat::dateTime($training->ends_at)]),
            'training_format' => $training->format->label(),
            'training_location' => DocumentFormat::text($training->location ?? $training->online_url),
            'training_trainer' => DocumentFormat::text($training->trainer),
            'training_duration' => (string) __('trainings.duration', [
                'hours' => intdiv($minutes, self::MINUTES_PER_HOUR),
                'minutes' => str_pad((string) ($minutes % self::MINUTES_PER_HOUR), 2, '0', STR_PAD_LEFT),
            ]),
        ];
    }
}
