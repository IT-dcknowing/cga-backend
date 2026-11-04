<?php

namespace App\Domain\Trainings\Http\Requests;

use App\Domain\Trainings\Http\Requests\Concerns\OrganizerTraining;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Actions de l'organisateur sur une de ses formations, sans données saisies : détail, publication, inscrits,
 * clôture de la session, évaluations (03-APPFLOW §3, §4).
 */
class OrganizerTrainingRequest extends FormRequest
{
    use OrganizerTraining;

    public function authorize(): bool
    {
        return $this->managesTraining();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
