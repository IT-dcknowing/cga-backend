<?php

namespace App\Domain\Concerns\Http\Requests;

use App\Domain\Concerns\Http\Requests\Concerns\ConcernAttachments;
use App\Domain\Concerns\Http\Requests\Concerns\RouteConcern;
use App\Support\Config\SettingsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST …/concerns/{concern}/messages et /network/concerns/{concernId}/messages : un message au fil
 * (F-PRE-02), en multipart avec ses pièces jointes ; « internal » (note interne invisible du demandeur)
 * réservé à qui traite la demande.
 */
class ConcernMessageRequest extends FormRequest
{
    use ConcernAttachments, RouteConcern;

    public function authorize(): bool
    {
        return Gate::allows('reply', $this->concern());
    }

    /**
     * Une note interne n'est écrite que par qui traite la demande.
     *
     * @throws AuthorizationException
     */
    protected function passedValidation(): void
    {
        if ($this->internal() && ! Gate::allows('handle', $this->concern())) {
            throw new AuthorizationException;
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(SettingsService $settings): array
    {
        return [
            'message' => ['required', 'string', $this->messageRule()],
            'internal' => ['sometimes', 'boolean'],
            ...$this->attachmentRules($settings),
        ];
    }

    public function body(): string
    {
        return (string) $this->validated('message');
    }

    public function internal(): bool
    {
        return (bool) $this->validated('internal', false);
    }
}
