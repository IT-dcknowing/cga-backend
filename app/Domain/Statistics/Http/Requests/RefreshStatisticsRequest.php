<?php

namespace App\Domain\Statistics\Http\Requests;

use App\Domain\Statistics\Models\StatOrgDaily;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST /{network|cga}/statistics/refresh : bouton « Actualiser » des tableaux de bord (02-TRD §9), sans paramètre ;
 * le périmètre (le CGA de l'espace, ou tout le réseau pour le CNCGA) est fixé par le serveur.
 */
class RefreshStatisticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('refresh', StatOrgDaily::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
