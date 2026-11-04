<?php

namespace App\Domain\Trainings\Http\Requests;

use App\Domain\Trainings\Models\Training;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Formation vue hors de son organisateur, sans données saisies : GET /cga/network-trainings/{trainingId} et
 * ses inscriptions du centre (un CGA), POST /me/trainings/{trainingId}/register (la personne ciblée).
 */
class CatalogTrainingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows($this->routeIs('me.*') ? 'attend' : 'browseNetwork', Training::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
