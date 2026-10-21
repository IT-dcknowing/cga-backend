<?php

namespace App\Domain\Imports\Support;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Valeur d'une cellule lue par OpenSpout : texte (nombre écrit sans décimale inutile), date
 * (cellule date, numéro de série Excel, ou texte jj/mm/aaaa ou aaaa-mm-jj).
 */
final class CellValue
{
    public static function text(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_string($value) => $value === '' ? null : $value,
            is_int($value) => (string) $value,
            is_float($value) => floor($value) === $value ? (string) (int) $value : (string) $value,
            is_bool($value) => $value ? '1' : '0',
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            default => null,
        };
    }

    /**
     * Date de la cellule ; null si vide, false si illisible.
     */
    public static function date(mixed $value): CarbonImmutable|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->startOfDay();
        }
        if (is_int($value) || is_float($value)) {
            return $value >= 1 && $value < 2958466
                ? CarbonImmutable::create(1899, 12, 30)->addDays((int) floor($value))
                : false;
        }
        if (! is_string($value)) {
            return false;
        }

        $text = trim($value);
        foreach (['d/m/Y', 'Y-m-d', 'd-m-Y', 'd.m.Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $text);
            if ($date !== false && $date->format($format) === $text) {
                return CarbonImmutable::instance($date);
            }
        }

        return false;
    }
}
