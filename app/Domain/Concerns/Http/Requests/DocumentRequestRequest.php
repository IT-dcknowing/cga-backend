<?php

namespace App\Domain\Concerns\Http\Requests;

use App\Domain\Concerns\Enums\DocumentRequestType;
use App\Domain\Concerns\Models\Concern;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /member/document-requests : « Demander un document » (F-DOC-06) — type (attestation, états
 * financiers, autre) et message, exigé pour un autre document.
 */
class DocumentRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('writeOwn', Concern::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(DocumentRequestType::class)],
            'message' => ['nullable', 'required_if:type,'.DocumentRequestType::Other->value, 'string', 'max:'.(int) config('cga.concerns.message_max_length')],
        ];
    }

    public function type(): DocumentRequestType
    {
        return DocumentRequestType::from((string) $this->validated('type'));
    }

    public function body(): ?string
    {
        $message = $this->validated('message');

        return is_string($message) && $message !== '' ? $message : null;
    }
}
