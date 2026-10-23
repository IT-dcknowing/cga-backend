<?php

namespace App\Domain\Documents\Http\Resources;

use App\Domain\Documents\Models\GeneratedDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Document vu par l'adhérent dans son espace (/adherent/documents, RG-DOC-04) : type, titre, numéro,
 * état (badge « Annulé » avec sa date), dates et le seul PDF (lien signé) ; ni le fichier Word ni les
 * informations internes du centre.
 *
 * @property GeneratedDocument $resource
 */
class MemberDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $document = $this->resource;

        return [
            'id' => $document->id,
            'type' => $document->type->value,
            'type_label' => $document->type->label(),
            'title' => $document->title,
            'category_label' => $document->category?->label(),
            'number' => $document->number,
            'version' => $document->version,
            'status' => $document->status->value,
            'status_label' => $document->status->label(),
            'issued_at' => $document->issued_at?->toIso8601ZuluString(),
            'valid_until' => $document->valid_until?->toDateString(),
            'cancelled_at' => $document->cancelled_at?->toIso8601ZuluString(),
            'pdf' => $this->whenLoaded('pdf', fn (): ?array => DocumentResource::file($document->pdf)),
        ];
    }
}
