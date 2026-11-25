<?php

namespace App\Domain\Exports\Http\Requests;

use App\Domain\Exports\Models\Export;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * GET /{network|cga}/exports/{export} : un export de la personne, cherché dans l'espace actif (un export d'un autre
 * espace n'existe pas, 404 ; celui d'une autre personne de l'espace est refusé, 403).
 */
class ShowExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $export = $this->route('export');

        return $export instanceof Export && Gate::allows('view', $export);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
