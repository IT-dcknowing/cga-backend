<?php

namespace App\Domain\Statistics\Http\Resources;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Support\Files\StorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une plaquette statistique (F-STA-03) : année, état de production, date, et le PDF prêt avec son lien de
 * téléchargement temporaire signé.
 *
 * @property GeneratedDocument $resource
 */
class BrochureResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $document = $this->resource;
        $pdf = $document->status === DocumentStatus::Valid ? $document->pdf : null;

        return [
            'id' => $document->id,
            'title' => $document->title,
            'year' => $document->fiscal_year,
            'status' => $document->status->value,
            'status_label' => $document->status->label(),
            'issued_at' => $document->issued_at?->toIso8601String(),
            'pdf' => $pdf === null ? null : [
                'name' => $pdf->original_name,
                'size_bytes' => $pdf->size_bytes,
                'url' => app(StorageService::class)->temporaryUrl($pdf),
            ],
        ];
    }
}
