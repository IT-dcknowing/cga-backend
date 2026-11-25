<?php

namespace App\Domain\Trainings\Http\Requests;

use App\Domain\Trainings\Http\Requests\Concerns\OrganizerTraining;
use App\Domain\Trainings\Models\Training;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * GET /{network|cga}/trainings/{training}/candidates et /cga/network-trainings/{trainingId}/candidates :
 * personnes ciblées à inscrire (« Inscrire des personnes ») — recherche facultative sur le nom.
 */
class TrainingCandidatesRequest extends FormRequest
{
    use OrganizerTraining;

    public function authorize(): bool
    {
        return $this->routeIs('cga.network-trainings.*')
            ? Gate::allows('browseNetwork', Training::class)
            : $this->managesTraining();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['search' => ['nullable', 'string', 'max:100']];
    }

    public function search(): ?string
    {
        $search = $this->validated('search');

        return is_string($search) && trim($search) !== '' ? trim($search) : null;
    }
}
