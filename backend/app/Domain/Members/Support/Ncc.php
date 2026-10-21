<?php

namespace App\Domain\Members\Support;

/**
 * Numéro de compte contribuable (F-ADH-02) : 7 chiffres et une lettre ; espaces, points et tirets
 * tolérés à la saisie, lettre mise en majuscule.
 */
final class Ncc
{
    public const PATTERN = '/^[0-9]{7}[A-Z]$/';

    public static function normalize(string $input): string
    {
        return strtoupper((string) preg_replace('/[\s.\-]+/', '', $input));
    }

    public static function isValid(string $input): bool
    {
        return preg_match(self::PATTERN, self::normalize($input)) === 1;
    }
}
