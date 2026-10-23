<?php

namespace App\Domain\Documents\Http\Requests;

use App\Domain\Documents\Models\GeneratedDocument;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/v1/cga/documents/{document}/cancel : annulation d'un document émis (F-DOC-03), motif
 * obligatoire ; gérant et expert-comptable (documents.cancel).
 */
class CancelDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('cancel', $this->document());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }

    /**
     * @throws AuthorizationException route sans document
     */
    public function document(): GeneratedDocument
    {
        $document = $this->route('document');

        return $document instanceof GeneratedDocument ? $document : throw new AuthorizationException;
    }
}
