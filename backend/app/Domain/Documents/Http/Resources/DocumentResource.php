<?php

namespace App\Domain\Documents\Http\Resources;

use App\Domain\Documents\Actions\RegenerateDocument;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Models\File;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Documents\Support\VerificationCode;
use App\Support\Files\StorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Document d'un dossier vu par le CGA (F-DOC-01, RG-DOC-03) : type, titre, catégorie d'un dépôt,
 * numéro, version et document remplacé, état, dates d'émission, de validité et d'annulation, empreinte
 * SHA-256 du PDF, adresse de vérification publique du QR code d'un document émis (F-DOC-04), fichiers
 * PDF et Word (liens de téléchargement signés, 02-TRD §6.4) une fois produits, actions permises.
 *
 * @property GeneratedDocument $resource
 */
class DocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $document = $this->resource;
        $valid = $document->status === DocumentStatus::Valid;

        return [
            'id' => $document->id,
            'type' => $document->type->value,
            'type_label' => $document->type->label(),
            'title' => $document->title,
            'category' => $document->category?->value,
            'category_label' => $document->category?->label(),
            'number' => $document->number,
            'version' => $document->version,
            'replaces_document_id' => $document->replaces_document_id,
            'status' => $document->status->value,
            'status_label' => $document->status->label(),
            'member' => $this->whenLoaded('member', fn (): ?array => $document->member === null ? null : [
                'id' => $document->member->id,
                'company_name' => $document->member->company_name,
            ]),
            'issued_at' => $document->issued_at?->toIso8601ZuluString(),
            'valid_until' => $document->valid_until?->toDateString(),
            'fiscal_year' => $document->fiscal_year,
            'cancelled_at' => $document->cancelled_at?->toIso8601ZuluString(),
            'cancel_reason' => $document->cancel_reason,
            'visible_to_member' => $document->visible_to_member,
            'sha256' => $document->sha256,
            'verification_url' => $this->verificationUrl($document),
            'files' => [
                'pdf' => $this->whenLoaded('pdf', fn (): ?array => self::file($document->pdf)),
                'docx' => $this->whenLoaded('docx', fn (): ?array => self::file($document->docx)),
            ],
            'created_at' => $document->created_at?->toIso8601ZuluString(),
            'can' => [
                'retry' => $document->status === DocumentStatus::Failed && Gate::allows('retry', $document),
                'regenerate' => $valid && in_array($document->type, RegenerateDocument::TYPES, true) && Gate::allows('regenerate', $document),
                'cancel' => $valid && Gate::allows('cancel', $document),
            ],
        ];
    }

    /**
     * Adresse imprimée dans le QR code, une fois le document émis (valide ou annulé).
     */
    private function verificationUrl(GeneratedDocument $document): ?string
    {
        $issued = in_array($document->status, [DocumentStatus::Valid, DocumentStatus::Cancelled], true);

        return $issued && $document->verification_token !== null ? app(VerificationCode::class)->url($document->verification_token) : null;
    }

    /**
     * @return array{id: string, name: string, size_bytes: int, url: string}|null
     */
    public static function file(?File $file): ?array
    {
        return $file === null ? null : [
            'id' => $file->id,
            'name' => $file->original_name,
            'size_bytes' => $file->size_bytes,
            'url' => app(StorageService::class)->temporaryUrl($file),
        ];
    }
}
