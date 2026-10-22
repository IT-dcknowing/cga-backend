<?php

namespace App\Domain\Documents\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Présentation des valeurs dans les documents (02-TRD §8.1) : montants en FCFA groupés par milliers,
 * dates en toutes lettres (« 1er mars 2026 »), champ vide marqué d'un tiret. Textes :
 * lang/fr/documents.php.
 */
final class DocumentFormat
{
    public static function text(?string $value): string
    {
        $value = trim((string) $value);

        return $value === '' ? self::empty() : $value;
    }

    public static function amount(?int $amount): string
    {
        return $amount === null ? self::empty() : (string) __('documents.amount', ['amount' => number_format($amount, 0, ',', ' ')]);
    }

    public static function date(?CarbonInterface $date): string
    {
        if ($date === null) {
            return self::empty();
        }

        $date = CarbonImmutable::instance($date)->locale(app()->getLocale());
        $day = $date->day === 1 ? (string) __('documents.first_day') : (string) $date->day;

        return $day.' '.$date->translatedFormat('F Y');
    }

    private static function empty(): string
    {
        return (string) __('documents.empty');
    }
}
