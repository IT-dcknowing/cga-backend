<?php

namespace App\Domain\Exports\Http\Requests;

use App\Domain\Exports\Models\Export;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * GET /{network|cga}/exports : derniers exports de la personne dans l'espace actif (au plus cga.exports.history_items),
 * sans paramètre.
 */
class ListExportsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Export::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
