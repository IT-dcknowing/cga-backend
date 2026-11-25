<?php

namespace App\Domain\Documents\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use NumberFormatter;

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

    /**
     * Date et heure (20 octobre 2026 à 10 h 05), pour l'horodatage d'une certification.
     */
    public static function dateTime(?CarbonInterface $moment): string
    {
        return $moment === null ? self::empty() : (string) __('documents.date_time', [
            'date' => self::date($moment),
            'time' => CarbonImmutable::instance($moment)->setTimezone((string) config('app.timezone'))->format('H \h i'),
        ]);
    }

    /**
     * Taux en pourcentage (18 %, 9,5 %).
     */
    public static function rate(float|int $rate): string
    {
        return (string) __('documents.rate', ['rate' => rtrim(rtrim(number_format((float) $rate, 2, ',', ' '), '0'), ',')]);
    }

    /**
     * Date courte (02/01/2026), pour les tableaux et les numéros datés.
     */
    public static function shortDate(?CarbonInterface $date): string
    {
        return $date === null ? self::empty() : $date->format('d/m/Y');
    }

    /**
     * Montant en toutes lettres (vingt-cinq mille francs CFA).
     */
    public static function amountInWords(int $amount): string
    {
        $words = (new NumberFormatter(app()->getLocale(), NumberFormatter::SPELLOUT))->format($amount);

        return (string) __('documents.amount_words', ['words' => is_string($words) ? $words : (string) $amount]);
    }

    /**
     * Forme juridique en toutes lettres (SARL → Société à responsabilité limitée) ; une forme inconnue
     * reste telle quelle.
     */
    public static function legalForm(?string $form): string
    {
        $key = 'documents.legal_forms.'.mb_strtoupper(trim((string) $form));

        return trans()->has($key) ? (string) __($key) : self::text($form);
    }

    private static function empty(): string
    {
        return (string) __('documents.empty');
    }
}
