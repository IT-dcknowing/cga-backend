<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Extensions PostgreSQL optionnelles (02-TRD §12.1). L'hébergement cible (PostgreSQL 13, mutualisé)
 * ne fournit pas forcément unaccent ou pg_trgm : le schéma et le code fonctionnent sans elles, avec un
 * repli en SQL standard, et en profitent dès qu'elles sont installées (php artisan db:extensions).
 *
 * citext n'en fait pas partie : les e-mails sont stockés en minuscules et leur unicité porte sur
 * lower(email), ce qui donne le même résultat sans extension.
 */
final class Extensions
{
    /**
     * Extension => objets qui en dépendent, créés quand elle est installée et que leur table existe.
     *
     * @var array<string, list<array{table: string, sql: string}>>
     */
    public const OPTIONAL = [
        'unaccent' => [],
        'pg_trgm' => [
            // Accélère la recherche LIKE '%…%' sur les dossiers d'adhérents (ListMembersRequest).
            ['table' => 'members', 'sql' => 'CREATE INDEX IF NOT EXISTS members_search_text_trgm ON members USING gin (search_text gin_trgm_ops)'],
        ],
    ];

    /** Lettres accentuées ramenées à leur lettre de base par le repli de unaccent(). */
    private const ACCENTED = 'àáâãäåçèéêëìíîïñòóôõöùúûüýÿÀÁÂÃÄÅÇÈÉÊËÌÍÎÏÑÒÓÔÕÖÙÚÛÜÝ';

    private const PLAIN = 'aaaaaaceeeeiiiinooooouuuuyyAAAAAACEEEEIIIINOOOOOUUUUY';

    /** @var array<string, bool> */
    private static array $installed = [];

    public static function installed(string $name): bool
    {
        return self::$installed[$name] ??= DB::table('pg_extension')->where('extname', $name)->exists();
    }

    public static function available(string $name): bool
    {
        return DB::table('pg_available_extensions')->where('name', $name)->exists();
    }

    /**
     * Installe chaque extension optionnelle proposée par le serveur et crée les objets qui en
     * dépendent. Sans effet sur ce qui existe déjà ; une extension absente ou refusée (droits
     * insuffisants) est ignorée sans interrompre la migration en cours.
     *
     * @return array<string, string> extension => installed | unavailable | refused
     */
    public static function sync(): array
    {
        $status = [];

        foreach (self::OPTIONAL as $name => $dependents) {
            self::flush();

            if (! self::installed($name)) {
                if (! self::available($name)) {
                    $status[$name] = 'unavailable';

                    continue;
                }

                $create = 'CREATE EXTENSION IF NOT EXISTS '.Constraints::identifier($name);

                try {
                    // Point de sauvegarde : un refus n'annule pas la transaction de la migration.
                    // @phpstan-ignore cga.rawSqlWithVariable (nom d'extension écrit dans OPTIONAL et vérifié)
                    DB::transaction(static fn () => DB::statement($create));
                } catch (Throwable) {
                    $status[$name] = 'refused';

                    continue;
                }
            }

            foreach ($dependents as $dependent) {
                if ((bool) DB::scalar('SELECT to_regclass(?) IS NOT NULL', [$dependent['table']])) {
                    DB::statement($dependent['sql']);
                }
            }

            $status[$name] = 'installed';
        }

        self::flush();

        return $status;
    }

    /**
     * Expression SQL sans accents : unaccent() si l'extension est installée, sinon translate() sur
     * les lettres accentuées du français et replace() sur les ligatures œ et æ.
     *
     * @param  string  $expression  expression SQL écrite dans le code ou paramètre lié (?)
     */
    public static function unaccent(string $expression): string
    {
        if (self::installed('unaccent')) {
            return "unaccent({$expression})";
        }

        return "replace(replace(replace(replace(translate({$expression}, '".self::ACCENTED."', '".self::PLAIN."'), 'œ', 'oe'), 'Œ', 'OE'), 'æ', 'ae'), 'Æ', 'AE')";
    }

    /**
     * Oublie les extensions mémorisées (tests, ou après db:extensions dans un processus long).
     */
    public static function flush(): void
    {
        self::$installed = [];
    }
}
