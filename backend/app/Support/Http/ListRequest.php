<?php

namespace App\Support\Http;

use App\Support\Config\SettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Demande d'une liste (02-TRD §4.3, RG-GEN-03) : pagination (?page, ?per_page borné par
 * pagination.max), filtres (?filter[…]) et tri (?sort=champ ou -champ), limités à une liste blanche
 * (02-TRD §6.7). Seules les valeurs validées sont transmises à la requête de la liste.
 */
abstract class ListRequest extends FormRequest
{
    /**
     * Filtres permis et leurs règles de validation.
     *
     * @return array<string, list<mixed>>
     */
    abstract protected function filters(): array;

    /**
     * Champs de tri permis.
     *
     * @return list<string>
     */
    abstract protected function sorts(): array;

    /**
     * @return array<string, mixed>
     */
    public function rules(SettingsService $settings): array
    {
        $sorts = $this->sorts();
        $rules = [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.(int) $settings->get('pagination.max')],
            'sort' => ['sometimes', 'string', Rule::in([...$sorts, ...array_map(static fn (string $sort): string => '-'.$sort, $sorts)])],
            'filter' => ['sometimes', 'array:'.implode(',', array_keys($this->filters()))],
        ];

        foreach ($this->filters() as $filter => $filterRules) {
            $rules["filter.{$filter}"] = ['sometimes', ...$filterRules];
        }

        return $rules;
    }

    public function perPage(SettingsService $settings): int
    {
        return (int) ($this->validated('per_page') ?? $settings->get('pagination.default'));
    }

    public function page(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }

    /**
     * Requête réduite aux filtres et au tri validés, pour QueryBuilder (spatie/laravel-query-builder).
     */
    public function listQuery(): Request
    {
        return Request::create('/', 'GET', array_filter([
            'filter' => $this->validated('filter'),
            'sort' => $this->validated('sort'),
        ], static fn (mixed $value): bool => $value !== null));
    }
}
