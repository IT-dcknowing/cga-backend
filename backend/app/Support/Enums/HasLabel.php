<?php

namespace App\Support\Enums;

use Illuminate\Support\Str;

/**
 * Libellé d'un enum adossé à des chaînes, lu dans lang/{locale}/enums.php (02-TRD §7.6 : les libellés
 * ne sont jamais écrits dans le code métier).
 */
trait HasLabel
{
    public function label(): string
    {
        return __('enums.'.static::translationGroup().'.'.$this->value);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Groupe du fichier de traduction : nom de l'enum en snake_case (Role → role).
     */
    public static function translationGroup(): string
    {
        return Str::snake(class_basename(static::class));
    }
}
