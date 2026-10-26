<?php

namespace App\Support\Database;

use BackedEnum;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Contraintes CHECK des migrations (05-BACKEND-SCHEMA §0 : statuts, motifs et rôles en varchar avec
 * CHECK, valeurs = enums PHP). Le schéma de Laravel ne les déclare pas : elles passent par ici, avec
 * des identifiants vérifiés et des valeurs issues des enums du code, jamais d'une donnée utilisateur.
 */
final class Constraints
{
    /**
     * Limite la colonne aux valeurs de l'enum (une valeur nulle reste permise si la colonne l'est).
     *
     * @param  class-string<BackedEnum>  $enum
     */
    public static function enum(string $table, string $column, string $enum): void
    {
        self::in($table, $column, array_map(static fn (BackedEnum $case): string => (string) $case->value, $enum::cases()));
    }

    /**
     * @param  list<string>  $values
     */
    public static function in(string $table, string $column, array $values): void
    {
        $quoted = implode(', ', array_map(static fn (string $value): string => DB::getPdo()->quote($value), $values));

        self::check($table, "{$table}_{$column}_check", self::identifier($column)." IN ({$quoted})");
    }

    /**
     * @param  string  $expression  expression SQL écrite dans la migration
     */
    public static function check(string $table, string $name, string $expression): void
    {
        $sql = sprintf('ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s)', self::identifier($table), self::identifier($name), $expression);

        // @phpstan-ignore cga.rawSqlWithVariable (identifiants vérifiés, expression et valeurs écrites dans le code)
        DB::statement($sql);
    }

    /**
     * Nom de table, de colonne ou de contrainte PostgreSQL : minuscules, chiffres et _, 63 caractères au plus.
     */
    public static function identifier(string $name): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $name) !== 1) {
            throw new InvalidArgumentException("Identifiant SQL refusé : {$name}");
        }

        return $name;
    }
}
