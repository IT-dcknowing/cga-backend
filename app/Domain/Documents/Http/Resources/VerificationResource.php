<?php

namespace App\Domain\Documents\Http\Resources;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Models\GeneratedDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Réponse de la page publique /verifier/{code} (F-DOC-04, 02-TRD §6.6) : uniquement les cinq
 * informations prévues — document valide ou annulé (avec la date de l'annulation), type et date du
 * document, CGA émetteur, nom de l'adhérent, numéro d'adhésion. Ni identifiant, ni numéro du document,
 * ni fichier.
 *
 * @property GeneratedDocument $resource
 */
class VerificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $document = $this->resource;
        $cancelled = $document->status === DocumentStatus::Cancelled;

        return [
            'status' => $document->status->value,
            'status_label' => $document->status->label(),
            'cancelled_on' => $cancelled ? $document->cancelled_at?->toDateString() : null,
            'type' => $document->type->value,
            'type_label' => $document->type->label(),
            'issued_on' => $document->issued_at?->toDateString(),
            'issuer' => $document->organization->name,
            'member_name' => $document->member?->company_name,
            'membership_number' => $document->member?->membership_number,
        ];
    }
}
