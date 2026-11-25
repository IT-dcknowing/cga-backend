<?php

namespace App\Support\Http\Query;

use App\Support\Database\Constraints;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * Filtre ?filter[search]= : texte contenu dans l'une des colonnes, sans tenir compte de la casse ni
 * des accents (PostgreSQL unaccent, 02-TRD §4.2). Les jokers % et _ saisis sont pris littéralement.
 *
 * @implements Filter<Model>
 */
final class SearchFilter implements Filter
{
    /**
     * @param  list<string>  $columns  colonnes écrites dans le code
     */
    public function __construct(private readonly array $columns)
    {
        foreach ($columns as $column) {
            Constraints::identifier($column);
        }
    }

    /**
     * @param  Builder<Model>  $query
     */
    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        $text = is_array($value) ? implode(',', $value) : (string) $value;
        $term = '%'.addcslashes(trim($text), '\\%_').'%';

        $query->where(function (Builder $search) use ($term): void {
            foreach ($this->columns as $column) {
                // @phpstan-ignore cga.rawSqlWithVariable (colonne écrite dans le code et vérifiée ; le texte cherché est une valeur liée)
                $search->orWhereRaw("unaccent({$column}::text) ILIKE unaccent(?)", [$term]);
            }
        });
    }
}
