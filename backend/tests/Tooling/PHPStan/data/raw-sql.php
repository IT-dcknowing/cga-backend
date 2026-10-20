<?php

namespace Tests\Tooling\PHPStan\data;

use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ExempleSqlBrut
{
    /**
     * @param  Builder<Model>  $query
     */
    public function interdit(Builder $query, string $tri, string $recherche): void
    {
        $query->whereRaw("name ILIKE '%{$recherche}%'");
        $query->orderByRaw($tri);
        DB::raw('lower('.$tri.')');
        DB::statement("DROP TABLE {$tri}");
        User::whereRaw($recherche);
        DB::connection()->statement($tri);
    }

    /**
     * @param  Builder<Model>  $query
     */
    public function autorise(Builder $query, string $recherche, Journal $journal): void
    {
        $query->whereRaw('unaccent(search_text) ILIKE unaccent(?)', ["%{$recherche}%"]);
        $query->orderByRaw('created_at DESC');
        DB::raw('count(*) as total');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        // Une méthode « statement » d'un objet qui n'est pas une base de données.
        $journal->statement($recherche);
    }
}

class Journal
{
    public function statement(string $ligne): void {}
}
