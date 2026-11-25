<?php

namespace App\Domain\Imports\Models;

use App\Domain\Imports\Enums\ImportRowStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sort d'une ligne du fichier importé (RG-IMP-02) : statut, motifs ou champs manquants, objet créé.
 * Cloisonnée par son lot.
 *
 * @property int $id
 * @property string $batch_id
 * @property int $row_number
 * @property ImportRowStatus $status
 * @property list<string> $messages
 * @property string|null $created_entity_id
 */
class ImportRow extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ImportRowStatus::class,
            'messages' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ImportBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'batch_id');
    }
}
