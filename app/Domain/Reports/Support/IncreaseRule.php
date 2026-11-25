<?php

namespace App\Domain\Reports\Support;

/**
 * Règle « cotisation augmentée » (RG-RPT-08) : il y a hausse quand la valeur de l'année (ou du
 * trimestre) dépasse celle de la période de comparaison d'au moins le pourcentage fixé par le réseau
 * (0 % : toute hausse). Sans valeur de comparaison connue et positive, aucune hausse n'est retenue.
 */
final class IncreaseRule
{
    public static function isIncrease(?int $previous, ?int $current, float $minimumPercent): bool
    {
        if ($previous === null || $current === null || $previous <= 0 || $current <= $previous) {
            return false;
        }

        return self::percent($previous, $current) >= $minimumPercent;
    }

    /** Hausse en pourcentage, arrondie au dixième. */
    public static function percent(int $previous, int $current): float
    {
        return $previous > 0 ? round(100 * ($current - $previous) / $previous, 1) : 0.0;
    }
}
