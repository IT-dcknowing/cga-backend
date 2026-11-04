<?php

namespace App\Domain\Trainings\Http\Requests;

use App\Domain\Trainings\Http\Requests\Concerns\OrganizerTraining;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /{network|cga}/trainings/{training}/cancel : « Annuler la formation », avec son motif.
 */
class CancelTrainingRequest extends FormRequest
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
        return ['reason' => ['required', 'string', 'max:'.(int) config('cga.trainings.text_max_length')]];
    }

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }
}
