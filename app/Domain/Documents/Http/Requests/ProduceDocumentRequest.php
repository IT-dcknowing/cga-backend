<?php

namespace App\Domain\Documents\Http\Requests;

use App\Domain\Documents\Actions\ProduceMemberDocument;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Members\Http\Requests\Concerns\RouteMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/cga/members/{member}/documents : « Documents ▾ » de la fiche (F-DOC-01) — production
 * d'un document du dossier à la demande : contrat, attestation, reçu (paiement désigné) ou courrier
 * de radiation.
 */
class ProduceDocumentRequest extends FormRequest
{
    use RouteMember;

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
            'type' => ['required', 'string', Rule::in(array_map(static fn (DocumentType $type): string => $type->value, ProduceMemberDocument::TYPES))],
            'payment_id' => ['required_if:type,'.DocumentType::Receipt->value, 'prohibited_unless:type,'.DocumentType::Receipt->value, 'string', 'max:26'],
        ];
    }

    public function type(): DocumentType
    {
        return DocumentType::from((string) $this->validated('type'));
    }

    public function paymentId(): ?string
    {
        $id = $this->validated('payment_id');

        return is_string($id) ? $id : null;
    }
}
