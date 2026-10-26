<?php

namespace App\Domain\Referentials\ReferenceData\Datasets;

use App\Domain\Imports\Support\CellValue;
use App\Domain\Imports\Support\ImportWorkbook;

/**
 * Contrôles communs aux lignes des fichiers de référence : champ obligatoire, longueur, oui / non.
 */
final class Fields
{
    private const YES = ['oui', 'o', 'yes', 'vrai', 'true', '1', 'active', 'actif'];

    private const NO = ['non', 'n', 'no', 'faux', 'false', '0', 'inactive', 'inactif'];

    /**
     * @param  array<string, array{0: string|null, 1: int, 2: bool}>  $fields  libellé => [valeur, longueur maximale, obligatoire]
     * @return list<string>
     */
    public static function errors(array $fields): array
    {
        $errors = [];
        foreach ($fields as $label => [$value, $max, $required]) {
            if ($value === null && $required) {
                $errors[] = __('reference_data.required', ['field' => $label]);
            } elseif ($value !== null && mb_strlen($value) > $max) {
                $errors[] = __('reference_data.too_long', ['field' => $label, 'max' => $max]);
            }
        }

        return $errors;
    }

    /**
     * Oui / non d'une cellule.
     *
     * @return array{0: bool|null, 1: bool} [valeur, null si vide ; lisible]
     */
    public static function boolean(mixed $cell): array
    {
        $text = CellValue::text($cell);
        if ($text === null) {
            return [null, true];
        }

        $normalized = ImportWorkbook::normalize($text);

        return match (true) {
            in_array($normalized, self::YES, true) => [true, true],
            in_array($normalized, self::NO, true) => [false, true],
            default => [null, false],
        };
    }

    /**
     * Valeurs de la ligne à écrire : une cellule vide ne change rien.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function provided(array $attributes): array
    {
        return array_filter($attributes, static fn (mixed $value): bool => $value !== null);
    }
}
