<?php

namespace App\Domain\Members\Http\Resources;

use App\Domain\Members\Models\MemberPiece;
use App\Support\Files\StorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pièce d'un dossier (F-PJ-01, RG-PJ-01) : type, statut, fichier déposé (lien signé), dates. Une pièce
 * attendue d'un brouillon n'existe pas encore en base : elle est montrée « attendue ».
 *
 * @property MemberPiece $resource
 */
class PieceResource extends JsonResource
{
    public function __construct(MemberPiece $piece, private readonly bool $canUpload)
    {
        parent::__construct($piece);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $piece = $this->resource;

        return [
            'type' => $piece->piece_type->value,
            'type_label' => $piece->piece_type->label(),
            'status' => $piece->status->value,
            'status_label' => $piece->status->label(),
            'file' => $piece->file === null ? null : [
                'id' => $piece->file->id,
                'name' => $piece->file->original_name,
                'mime_type' => $piece->file->mime_type,
                'url' => app(StorageService::class)->temporaryUrl($piece->file),
            ],
            'received_at' => $piece->received_at?->toDateString(),
            'expires_at' => $piece->expires_at?->toDateString(),
            'can' => ['upload' => $this->canUpload],
        ];
    }
}
