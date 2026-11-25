<?php

namespace App\Domain\Imports\Support;

use App\Domain\Obligations\Enums\ObligationPeriodicity;
use App\Domain\Obligations\Enums\TaxNature;
use App\Domain\Obligations\Support\ObligationPeriod;
use Carbon\CarbonImmutable;

/**
 * Valeurs des fichiers d'historique (F-IMP-03) : montant entier en FCFA (espaces de milliers admis),
 * période au format des échéances (`2025`, `2025-S1`, `2025-T3`, `2025-03` ou `03/2025`), nature d'impôt
 * par son code ou son libellé.
 */
final class HistoryValues
{
    /**
     * Entier positif ou nul ; `null` si vide ou illisible (décimales, texte).
     */
    public static function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return floor($value) === $value ? (int) $value : null;
        }
        $text = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', (string) CellValue::text($value));

        return $text !== null && preg_match('/^-?\d{1,15}$/', $text) === 1 ? (int) $text : null;
    }

    /**
     * Période désignée ; `null` si illisible.
     */
    public static function period(?string $text): ?ObligationPeriod
    {
        $value = mb_strtoupper(trim((string) $text));
        $match = [];

        return match (true) {
            preg_match('/^(\d{4})$/', $value, $match) === 1 => ObligationPeriod::containing(ObligationPeriodicity::Yearly, CarbonImmutable::create((int) $match[1], 1, 1)),
            preg_match('/^(\d{4})-S([12])$/', $value, $match) === 1 => ObligationPeriod::containing(ObligationPeriodicity::HalfYearly, CarbonImmutable::create((int) $match[1], $match[2] === '1' ? 1 : 7, 1)),
            preg_match('/^(\d{4})-T([1-4])$/', $value, $match) === 1 => ObligationPeriod::containing(ObligationPeriodicity::Quarterly, CarbonImmutable::create((int) $match[1], ((int) $match[2] - 1) * 3 + 1, 1)),
            preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value, $match) === 1 => ObligationPeriod::containing(ObligationPeriodicity::Monthly, CarbonImmutable::create((int) $match[1], (int) $match[2], 1)),
            preg_match('/^(0[1-9]|1[0-2])\/(\d{4})$/', $value, $match) === 1 => ObligationPeriod::containing(ObligationPeriodicity::Monthly, CarbonImmutable::create((int) $match[2], (int) $match[1], 1)),
            default => null,
        };
    }

    /**
     * Nature d'impôt par son code (`tee`) ou son libellé (« Impôt microentreprise »).
     */
    public static function taxNature(?string $text): ?TaxNature
    {
        if ($text === null) {
            return null;
        }
        $wanted = ImportWorkbook::normalize($text);
        foreach (TaxNature::cases() as $nature) {
            if ($wanted === ImportWorkbook::normalize($nature->value) || $wanted === ImportWorkbook::normalize((string) __('enums.tax_nature.'.$nature->value))) {
                return $nature;
            }
        }

        return null;
    }
}
