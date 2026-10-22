<?php

namespace App\Domain\Documents\Http\Requests;

use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Members\Http\Requests\Concerns\RouteMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/cga/members/{member}/documents : « Documents ▾ » de la fiche (F-DOC-01) — production
 * d'un document du dossier à la demande. Les autres documents naissent de leur événement (paiement
 * confirmé, radiation…).
 */
class ProduceDocumentRequest extends FormRequest
{
    use RouteMember;

    /**
     * Documents produits à la demande depuis la fiche.
     */
    public const ON_DEMAND = [DocumentType::MembershipContract];

    public function authorize(): bool
    {
        return Gate::allows('produce', [GeneratedDocument::class, $this->member()]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(array_map(static fn (DocumentType $type): string => $type->value, self::ON_DEMAND))],
        ];
    }

    public function type(): DocumentType
    {
        return DocumentType::from((string) $this->validated('type'));
    }
}
