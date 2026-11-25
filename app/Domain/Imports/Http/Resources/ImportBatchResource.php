<?php

namespace App\Domain\Imports\Http\Resources;

use App\Domain\Imports\Enums\ImportStatus;
use App\Domain\Imports\Models\ImportBatch;
use App\Support\Files\StorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lot d'import (M17) : avancement (barre de progression), compteurs, fichier déposé, lien signé du
 * rapport d'anomalies une fois l'import fini (F-IMP-01).
 *
 * @property ImportBatch $resource
 */
class ImportBatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $batch = $this->resource;
        $finished = in_array($batch->status, [ImportStatus::Done, ImportStatus::Failed], true);

        return [
            'id' => $batch->id,
            'type' => $batch->type->value,
            'type_label' => $batch->type->label(),
            'status' => $batch->status->value,
            'status_label' => $batch->status->label(),
            'file_name' => $batch->file->original_name,
            'total_rows' => $batch->total_rows,
            'processed_rows' => $batch->processedRows(),
            'imported_rows' => $batch->imported_rows,
            'draft_rows' => $batch->draft_rows,
            'rejected_rows' => $batch->rejected_rows,
            'report' => ! $finished || $batch->report === null ? null : [
                'id' => $batch->report->id,
                'name' => $batch->report->original_name,
                'url' => app(StorageService::class)->temporaryUrl($batch->report),
            ],
            'created_by' => $batch->creator === null ? null : ['id' => $batch->creator->id, 'name' => $batch->creator->fullName()],
            'created_at' => $batch->created_at?->toIso8601ZuluString(),
            'finished_at' => $batch->finished_at?->toIso8601ZuluString(),
        ];
    }
}
