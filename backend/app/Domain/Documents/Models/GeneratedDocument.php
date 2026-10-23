<?php

namespace App\Domain\Documents\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Enums\UploadedDocumentCategory;
use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Models\Organization;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonInterface;
use Database\Factories\GeneratedDocumentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Document produit ou déposé par une organisation (F-DOC-01 à 05) : numéro, version (régénération,
 * duplicata), modèle utilisé, fichiers, jeton de vérification et empreinte SHA-256 du PDF (RG-DOC-09),
 * validité, annulation (jamais supprimé, RG-DOC-03), visibilité par l'adhérent, catégorie d'un document
 * déposé (F-DOC-05).
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $member_id
 * @property DocumentType $type
 * @property string $title
 * @property UploadedDocumentCategory|null $category
 * @property string|null $number
 * @property int $version
 * @property string|null $replaces_document_id
 * @property string|null $template_id
 * @property string|null $docx_file_id
 * @property string|null $pdf_file_id
 * @property string|null $xlsx_file_id
 * @property string|null $verification_token
 * @property string|null $sha256
 * @property DocumentStatus $status
 * @property CarbonInterface|null $issued_at
 * @property CarbonInterface|null $valid_until
 * @property int|null $fiscal_year
 * @property CarbonInterface|null $cancelled_at
 * @property string|null $cancelled_by
 * @property string|null $cancel_reason
 * @property bool $visible_to_member
 * @property string|null $source_type
 * @property string|null $source_id
 * @property string|null $generated_by
 * @property-read Organization $organization
 * @property-read Member|null $member
 * @property-read DocumentTemplate|null $template
 * @property-read File|null $pdf
 * @property-read File|null $docx
 * @property-read File|null $xlsx
 */
class GeneratedDocument extends Model
{
    /** @use HasFactory<GeneratedDocumentFactory> */
    use Auditable, BelongsToOrganization, HasFactory, HasUlids;

    /**
     * Écrit par les Actions des documents seulement.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * Le jeton de vérification ne sort que dans l'URL du QR code.
     *
     * @var list<string>
     */
    protected $hidden = ['verification_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'category' => UploadedDocumentCategory::class,
            'version' => 'integer',
            'fiscal_year' => 'integer',
            'issued_at' => 'immutable_datetime',
            'valid_until' => 'immutable_date',
            'cancelled_at' => 'immutable_datetime',
            'visible_to_member' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * Organisation émettrice (CGA, ou CNCGA pour les cotisations).
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<DocumentTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class);
    }

    /**
     * @return BelongsTo<File, $this>
     */
    public function pdf(): BelongsTo
    {
        return $this->belongsTo(File::class, 'pdf_file_id');
    }

    /**
     * @return BelongsTo<File, $this>
     */
    public function docx(): BelongsTo
    {
        return $this->belongsTo(File::class, 'docx_file_id');
    }

    /**
     * @return BelongsTo<File, $this>
     */
    public function xlsx(): BelongsTo
    {
        return $this->belongsTo(File::class, 'xlsx_file_id');
    }

    protected static function newFactory(): GeneratedDocumentFactory
    {
        return GeneratedDocumentFactory::new();
    }
}
