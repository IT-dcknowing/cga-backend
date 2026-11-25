<?php

namespace App\Domain\Trainings\Http\Requests;

use App\Domain\Trainings\Enums\RegistrationStatus;
use App\Domain\Trainings\Http\Requests\Concerns\OrganizerTraining;
use App\Domain\Trainings\Models\Training;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Inscriptions d'une formation, filtre facultatif par statut (onglets Inscrits, Liste d'attente, Présence) :
 * GET /{network|cga}/trainings/{training}/registrations (l'organisateur, toutes) et
 * GET /cga/network-trainings/{trainingId}/registrations (un CGA, celles de ses agents et adhérents).
 */
class TrainingRegistrationsRequest extends FormRequest
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
        return ['status' => ['nullable', Rule::enum(RegistrationStatus::class)]];
    }

    public function status(): ?RegistrationStatus
    {
        $status = $this->validated('status');

        return is_string($status) ? RegistrationStatus::from($status) : null;
    }
}
