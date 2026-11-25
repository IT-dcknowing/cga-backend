<?php

namespace App\Support\Http\Query;

use App\Support\Database\Constraints;
<<<<<<< HEAD
=======
use App\Support\Database\Extensions;
>>>>>>> cd06776774a9a4fdc9bf415c1ccb8b8ff0026d90
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * Filtre ?filter[search]= : texte contenu dans l'une des colonnes, sans tenir compte de la casse ni
<<<<<<< HEAD
 * des accents (PostgreSQL unaccent, 02-TRD §4.2). Les jokers % et _ saisis sont pris littéralement.
=======
 * des accents (unaccent si installée, sinon repli SQL : Extensions::unaccent, 02-TRD §4.2). Les jokers % et _ saisis sont pris littéralement.
>>>>>>> cd06776774a9a4fdc9bf415c1ccb8b8ff0026d90
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
<<<<<<< HEAD
                // @phpstan-ignore cga.rawSqlWithVariable (colonne écrite dans le code et vérifiée ; le texte cherché est une valeur liée)
                $search->orWhereRaw("unaccent({$column}::text) ILIKE unaccent(?)", [$term]);
=======
                $sql = Extensions::unaccent("{$column}::text").' ILIKE '.Extensions::unaccent('?');
                // @phpstan-ignore cga.rawSqlWithVariable (colonne écrite dans le code et vérifiée ; le texte cherché est une valeur liée)
                $search->orWhereRaw($sql, [$term]);
>>>>>>> cd06776774a9a4fdc9bf415c1ccb8b8ff0026d90
            }
        });
    }
}
