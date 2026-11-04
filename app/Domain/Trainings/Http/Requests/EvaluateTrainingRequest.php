<?php

namespace App\Domain\Trainings\Http\Requests;

use App\Domain\Trainings\Http\Requests\Concerns\RouteRegistration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST /me/training-registrations/{registration}/evaluation : « Évaluer » la session (F-FOR-04) — note de 1 à 5,
 * commentaire facultatif. Seule la personne inscrite évalue.
 */
class EvaluateTrainingRequest extends FormRequest
{
    use RouteRegistration;

    public function authorize(): bool
    {
        return Gate::allows('own', $this->registration());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'score' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:'.(int) config('cga.trainings.text_max_length')],
        ];
    }

    public function score(): int
    {
        return (int) $this->validated('score');
    }

    public function comment(): ?string
    {
        $comment = $this->validated('comment');

        return is_string($comment) && $comment !== '' ? $comment : null;
    }
}
