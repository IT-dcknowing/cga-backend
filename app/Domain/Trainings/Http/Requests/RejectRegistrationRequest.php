<?php

namespace App\Domain\Trainings\Http\Requests;

use App\Domain\Trainings\Http\Requests\Concerns\OrganizerTraining;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /{network|cga}/trainings/{training}/registrations/{registrationId}/reject : « Refuser » une inscription
 * en attente de validation (RG-FOR-05), motif facultatif.
 */
class RejectRegistrationRequest extends FormRequest
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
        return ['reason' => ['nullable', 'string', 'max:'.(int) config('cga.trainings.text_max_length')]];
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) && $reason !== '' ? $reason : null;
    }
}
