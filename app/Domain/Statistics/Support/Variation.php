<?php

namespace App\Domain\Statistics\Support;

/**
 * Indicateur de période avec sa variation par rapport à la même période de N-1 (F-DSH-04) : écart et écart en %
 * (null quand N-1 vaut 0 ou est inconnu).
 */
final class Variation
{
    private const PERCENT_ROUNDING = 1;

    /**
     * @return array{value: int|float|null, previous: int|float|null, change: int|float|null, change_pct: float|null}
     */
    public static function of(int|float|null $value, int|float|null $previous): array
    {
        $change = $value === null || $previous === null ? null : $value - $previous;

        return [
            'value' => $value,
            'previous' => $previous,
            'change' => $change,
            'change_pct' => $change === null || (float) $previous === 0.0 ? null : round(100 * $change / abs((float) $previous), self::PERCENT_ROUNDING),
        ];
    }
}
