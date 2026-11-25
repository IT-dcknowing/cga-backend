<?php

namespace App\Domain\Exports\Support;

use Carbon\CarbonInterface;

/**
 * Valeurs d'une cellule d'export : dates au format jj/mm/aaaa, horodatages au format jj/mm/aaaa hh:mm (fuseau de
 * l'application), montants en nombres entiers.
 */
final class ExportFormat
{
    public static function date(?CarbonInterface $date): ?string
    {
        return $date?->format((string) config('cga.exports.date_format'));
    }

    public static function dateTime(?CarbonInterface $moment): ?string
    {
        return $moment?->copy()->setTimezone((string) config('app.timezone'))->format((string) config('cga.exports.datetime_format'));
    }
}
