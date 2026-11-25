<?php

namespace App\Support\Validation\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use RuntimeException;

/**
 * Refuse les mots de passe les plus courants (02-TRD §6.1, A07) : liste locale de
 * config('auth.common_passwords_file') — les 10 000 mots de passe les plus fréquents (SecLists,
 * licence MIT), comparés sans tenir compte de la casse. Aucun appel à un service externe.
 */
class NotCommonPassword implements ValidationRule
{
    /**
     * @var array<string, true>|null
     */
    private static ?array $list = null;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && isset(self::list()[mb_strtolower($value)])) {
            $fail('validation.common_password')->translate();
        }
    }

    /**
     * @return array<string, true>
     */
    private static function list(): array
    {
        if (self::$list === null) {
            $file = (string) config('auth.common_passwords_file');
            // Sans la liste, aucun mot de passe ne serait refusé : son absence est une erreur.
            $lines = is_readable($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
            if ($lines === false || $lines === []) {
                throw new RuntimeException("Liste des mots de passe courants illisible : {$file}");
            }
            self::$list = array_fill_keys(array_map('mb_strtolower', $lines), true);
        }

        return self::$list;
    }
}
