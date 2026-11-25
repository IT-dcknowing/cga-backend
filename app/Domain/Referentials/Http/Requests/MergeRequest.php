<?php

namespace App\Domain\Referentials\Http\Requests;

use App\Domain\Referentials\Contracts\Referential;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Fusion d'une valeur de référentiel : identifiant de la valeur cible (même référentiel).
 */
class MergeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', $this->source());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'into_id' => ['required', 'string', 'max:26'],
        ];
    }

    public function targetId(): string
    {
        return (string) $this->validated('into_id');
    }

    /**
     * @throws AuthorizationException route sans valeur
     */
    private function source(): Model&Referential
    {
        foreach ((array) $this->route()?->parameters() as $parameter) {
            if ($parameter instanceof Model && $parameter instanceof Referential) {
                return $parameter;
            }
        }

        throw new AuthorizationException;
    }
}
