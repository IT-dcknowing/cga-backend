<?php

namespace Tests\Tooling\PHPStan\data;

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
    }

    /**
     * @param  Builder<Model>  $query
     */
    public function autorise(Builder $query, string $recherche): void
    {
        $query->whereRaw('unaccent(search_text) ILIKE unaccent(?)', ["%{$recherche}%"]);
        $query->orderByRaw('created_at DESC');
        DB::raw('count(*) as total');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
    }
}
