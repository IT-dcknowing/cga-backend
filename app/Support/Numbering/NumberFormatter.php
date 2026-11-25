<?php

namespace App\Support\Numbering;

/**
 * Format d'un numéro (02-TRD §7.1, paramètres numbering.*.pattern) : {YYYY} année, {ABR} abréviation
 * du CGA, {N:k} numéro d'ordre sur k chiffres au moins ({N} sans complément).
 */
final class NumberFormatter
{
    public static function format(string $pattern, int $number, ?int $year = null, ?string $abbreviation = null): string
    {
        $formatted = (string) preg_replace_callback(
            '/\{N(?::(\d))?\}/',
            static fn (array $match): string => str_pad((string) $number, isset($match[1]) ? (int) $match[1] : 1, '0', STR_PAD_LEFT),
            $pattern,
        );

        return strtr($formatted, ['{YYYY}' => (string) ($year ?? now()->year), '{ABR}' => (string) $abbreviation]);
    }
}
