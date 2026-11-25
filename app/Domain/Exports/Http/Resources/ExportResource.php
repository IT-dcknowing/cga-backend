<?php

namespace App\Domain\Exports\Http\Resources;

use App\Domain\Exports\Enums\ExportStatus;
use App\Domain\Exports\Models\Export;
use App\Support\Files\StorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un export (F-EXP-01) : liste, avancement, période, filtres, nombre de lignes, horodatages, et le fichier prêt avec
 * son lien de téléchargement temporaire signé.
 *
 * @property Export $resource
 */
class ExportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $export = $this->resource;
        $file = $export->status === ExportStatus::Done ? $export->file : null;

        return [
            'id' => $export->id,
            'type' => $export->type->value,
            'type_label' => $export->type->label(),
            'status' => $export->status->value,
            'status_label' => $export->status->label(),
            'period' => $export->period_from === null ? null : [
                'type' => $export->period_type,
                'from' => $export->period_from->toDateString(),
                'to' => $export->period_to?->toDateString(),
            ],
            'filters' => (object) $export->filters,
            'rows_count' => $export->rows_count,
            'requested_at' => $export->requested_at->toIso8601String(),
            'finished_at' => $export->finished_at?->toIso8601String(),
            'file' => $file === null ? null : [
                'name' => $file->original_name,
                'size_bytes' => $file->size_bytes,
                'url' => app(StorageService::class)->temporaryUrl($file),
            ],
        ];
    }
}
