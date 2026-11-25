<?php

namespace App\Domain\Obligations\Support;

use Illuminate\Validation\Rule;

/**
 * Règle d'échéance d'une obligation du catalogue (`obligation_types.due_rule`, 05-BACKEND-SCHEMA §4) :
 * - `day_of_next_month` : le jour `day` du mois qui suit la fin de la période ;
 * - `fixed_date` : le `day` du mois `month` de l'année qui suit la période ;
 * - `day_after_period_end` : `days` jours après la fin de la période.
 * Le catalogue de référence fixe la règle de chaque obligation ; cette classe n'en décide aucune.
 */
final class DueRule
{
    public const DAY_OF_NEXT_MONTH = 'day_of_next_month';

    public const FIXED_DATE = 'fixed_date';

    public const DAY_AFTER_PERIOD_END = 'day_after_period_end';

    public const TYPES = [self::DAY_OF_NEXT_MONTH, self::FIXED_DATE, self::DAY_AFTER_PERIOD_END];

    /**
     * Règles de validation d'une règle d'échéance, sous la clé `$prefix` (ex. `due_rule`).
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(string $prefix): array
    {
        $type = static fn (string $value): string => "required_if:{$prefix}.type,{$value}";

        return [
            $prefix => ['required', 'array'],
            "{$prefix}.type" => ['required', 'string', Rule::in(self::TYPES)],
            "{$prefix}.day" => [$type(self::DAY_OF_NEXT_MONTH), $type(self::FIXED_DATE), 'nullable', 'integer', 'between:1,31'],
            "{$prefix}.month" => [$type(self::FIXED_DATE), 'nullable', 'integer', 'between:1,12'],
            "{$prefix}.days" => [$type(self::DAY_AFTER_PERIOD_END), 'nullable', 'integer', 'between:0,366'],
        ];
    }
}
