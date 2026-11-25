<?php

namespace App\Domain\Reports\Http\Resources;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Reports\Models\QuarterlyReportVersion;
use App\Support\Files\StorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Version d'un rapport trimestriel (RG-RPT-05, onglet « Versions ») : numéro, motif, génération et
 * transmission horodatées, documents et liens de téléchargement (rapport PDF et Word, classeur de
 * compatibilité, bordereau) une fois produits. L'instantané n'est pas renvoyé.
 *
 * @property QuarterlyReportVersion $resource
 */
class QuarterlyReportVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $version = $this->resource;

        return [
            'id' => $version->id,
            'version' => $version->version,
            'reason' => $version->reason,
            'generated_at' => $version->generated_at?->toIso8601ZuluString(),
            'generated_by' => $version->generator?->fullName(),
            'transmitted_at' => $version->transmitted_at?->toIso8601ZuluString(),
            'documents' => [
                'report' => $this->document($version->pdfDocument, ['pdf', 'docx']),
                'workbook' => $this->document($version->xlsxDocument, ['xlsx']),
                'slip' => $this->document($version->slipDocument, ['pdf']),
            ],
        ];
    }

    /**
     * @param  list<string>  $formats
     * @return array<string, mixed>|null
     */
    private function document(?GeneratedDocument $document, array $formats): ?array
    {
        if ($document === null) {
            return null;
        }
        $files = [];
        if ($document->status === DocumentStatus::Valid) {
            foreach ($formats as $format) {
                $file = $document->{$format};
                $files[$format] = $file === null ? null : app(StorageService::class)->temporaryUrl($file);
            }
        }

        return [
            'id' => $document->id,
            'number' => $document->number,
            'version' => $document->version,
            'status' => $document->status->value,
            'status_label' => $document->status->label(),
            'files' => $files,
        ];
    }
}
