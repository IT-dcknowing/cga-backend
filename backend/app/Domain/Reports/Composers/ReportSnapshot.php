<?php

namespace App\Domain\Reports\Composers;

use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Reports\Models\QuarterlyReportVersion;
use LogicException;

/**
 * Instantané de la version de rapport d'un document produit (RG-RPT-05) : les documents d'une version
 * sont remplis depuis l'objet de données enregistré, jamais recalculé.
 */
final class ReportSnapshot
{
    public const SOURCE_TYPE = 'quarterly_report_version';

    /**
     * @return array<string, mixed>
     *
     * @throws LogicException document sans version de rapport
     */
    public static function of(GeneratedDocument $document): array
    {
        $version = $document->source_type === self::SOURCE_TYPE ? QuarterlyReportVersion::query()->find($document->source_id) : null;
        if ($version === null || $version->snapshot === null) {
            throw new LogicException("Document {$document->id} sans instantané de rapport.");
        }

        return $version->snapshot;
    }
}
