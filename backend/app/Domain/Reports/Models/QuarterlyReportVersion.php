<?php

namespace App\Domain\Reports\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonInterface;
use Database\Factories\QuarterlyReportVersionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Version générée d'un rapport trimestriel (RG-RPT-05) : instantané complet de l'objet de données
 * (agrégats, séries des graphiques, textes, paramétrage RG-RPT-08, source de chaque donnée), documents
 * produits (rapport modernisé PDF et Word, classeur de compatibilité, bordereau), horodatages. Une
 * version générée ne change plus ; une correction en ouvre une nouvelle, avec son motif.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $report_id
 * @property int $version
 * @property string|null $reason
 * @property array<string, mixed>|null $snapshot
 * @property string|null $xlsx_document_id
 * @property string|null $docx_document_id
 * @property string|null $pdf_document_id
 * @property string|null $slip_document_id
 * @property CarbonInterface|null $generated_at
 * @property string|null $generated_by
 * @property CarbonInterface|null $transmitted_at
 * @property-read QuarterlyReport $report
 * @property-read GeneratedDocument|null $xlsxDocument
 * @property-read GeneratedDocument|null $docxDocument
 * @property-read GeneratedDocument|null $pdfDocument
 * @property-read GeneratedDocument|null $slipDocument
 * @property-read User|null $generator
 */
class QuarterlyReportVersion extends Model
{
    /** @use HasFactory<QuarterlyReportVersionFactory> */
    use Auditable, BelongsToOrganization, HasFactory, HasUlids;

    /**
     * Écrit par les Actions du rapport seulement.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'snapshot' => 'array',
            'generated_at' => 'immutable_datetime',
            'transmitted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<QuarterlyReport, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(QuarterlyReport::class, 'report_id');
    }

    /**
     * @return BelongsTo<GeneratedDocument, $this>
     */
    public function xlsxDocument(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class, 'xlsx_document_id');
    }

    /**
     * @return BelongsTo<GeneratedDocument, $this>
     */
    public function docxDocument(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class, 'docx_document_id');
    }

    /**
     * @return BelongsTo<GeneratedDocument, $this>
     */
    public function pdfDocument(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class, 'pdf_document_id');
    }

    /**
     * @return BelongsTo<GeneratedDocument, $this>
     */
    public function slipDocument(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class, 'slip_document_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    protected static function newFactory(): QuarterlyReportVersionFactory
    {
        return QuarterlyReportVersionFactory::new();
    }
}
