<?php

namespace App\Domain\Imports\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Documents\Models\File;
use App\Domain\Imports\Enums\ImportStatus;
use App\Domain\Imports\Enums\ImportType;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Import d'un fichier Excel (M17) par l'organisation de l'espace actif : fichier déposé, compteurs,
 * rapport d'anomalies ligne par ligne. Écrit par StartImport et le Job ProcessImport seulement.
 *
 * @property string $id
 * @property string $organization_id
 * @property ImportType $type
 * @property string $file_id
 * @property ImportStatus $status
 * @property int $total_rows
 * @property int $imported_rows
 * @property int $draft_rows
 * @property int $rejected_rows
 * @property string|null $report_file_id
 * @property string|null $created_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $finished_at
 * @property-read File $file
 * @property-read File|null $report
 * @property-read User|null $creator
 */
class ImportBatch extends Model
{
    use Auditable, BelongsToOrganization, HasUlids;

    public const UPDATED_AT = null;

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
            'type' => ImportType::class,
            'status' => ImportStatus::class,
            'total_rows' => 'integer',
            'imported_rows' => 'integer',
            'draft_rows' => 'integer',
            'rejected_rows' => 'integer',
            'created_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    /**
     * Lignes déjà traitées (barre de progression).
     */
    public function processedRows(): int
    {
        return $this->imported_rows + $this->draft_rows + $this->rejected_rows;
    }

    /**
     * @return HasMany<ImportRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class, 'batch_id');
    }

    /**
     * @return BelongsTo<File, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /**
     * @return BelongsTo<File, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(File::class, 'report_file_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
