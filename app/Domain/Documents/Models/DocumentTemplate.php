<?php

namespace App\Domain\Documents\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Documents\Enums\DocumentType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modèle de document versionné (02-TRD §8.1, F-ADM-02) : fichier DOCX ou XLSX balisé ${…}, variables
 * attendues, un seul actif par type ; commun à tout le réseau, validé par le CNCGA.
 *
 * @property string $id
 * @property DocumentType $type
 * @property string $version
 * @property string $file_id
 * @property list<string> $variables
 * @property bool $is_active
 * @property CarbonInterface|null $approved_by_cncga_at
 * @property string|null $notes
 * @property-read File $file
 * @property-read int|null $documents_count
 */
class DocumentTemplate extends Model
{
    use Auditable, HasUlids;

    /**
     * Écrit par les Actions des modèles seulement.
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
            'type' => DocumentType::class,
            'variables' => 'array',
            'is_active' => 'boolean',
            'approved_by_cncga_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<File, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /**
     * Documents émis avec cette version, dans tous les centres (RG-DATA-03 : chacun la garde).
     *
     * @return HasMany<GeneratedDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class, 'template_id');
    }
}
