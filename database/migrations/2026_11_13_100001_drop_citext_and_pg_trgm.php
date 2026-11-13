<?php

use App\Support\Database\Extensions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Retrait de citext (cible PostgreSQL 13.23 mutualisé, sans extension garantie, 02-TRD §12.1).
 * Les migrations d'origine ont été modifiées en place : une base migrée avant ce changement garde des
 * colonnes citext. Cette migration les repasse en varchar et reste sans effet sur une base neuve.
 * unaccent et pg_trgm deviennent optionnelles (App\Support\Database\Extensions).
 *
 * Sans citext, l'e-mail d'un utilisateur n'est plus insensible à la casse : la connexion compare une
 * saisie ramenée en minuscules (Identifier), les e-mails stockés sont donc ramenés en minuscules et
 * l'unicité porte sur lower(email).
 */
return new class extends Migration
{
    /** @var list<string> */
    private const EMAIL_TABLES = ['users', 'organizations', 'business_referrers', 'members'];

    public function up(): void
    {
        foreach (self::EMAIL_TABLES as $table) {
            if ($this->isCitext($table, 'email')) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN email TYPE varchar(255) USING email::text");
            }
        }

        $duplicates = DB::table('users')
            ->whereNotNull('email')
            ->groupByRaw('lower(email)')
            ->havingRaw('count(*) > 1')
            ->pluck(DB::raw('lower(email)'));

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('E-mails utilisateurs en double à la casse près, à corriger avant migration : '.$duplicates->implode(', '));
        }

        DB::statement('UPDATE users SET email = lower(email) WHERE email <> lower(email)');
        DB::statement('DROP INDEX IF EXISTS users_email_unique');
        DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (lower(email)) WHERE email IS NOT NULL');

        // citext n'est plus utilisée. Sans CASCADE : échoue si un objet en dépend encore.
        DB::statement('DROP EXTENSION IF EXISTS citext');

        // unaccent et pg_trgm restent optionnelles : gardées si présentes, ajoutées si le serveur les propose.
        Extensions::sync();
    }

    public function down(): void
    {
        // Les extensions ne sont pas recréées : elles n'existent pas forcément sur la cible PostgreSQL 13.
        DB::statement('DROP INDEX IF EXISTS users_email_unique');
        DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (email) WHERE email IS NOT NULL');
    }

    private function isCitext(string $table, string $column): bool
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', DB::raw('current_schema()'))
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->where('udt_name', 'citext')
            ->exists();
    }
};
