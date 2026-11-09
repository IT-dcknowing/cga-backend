<?php

namespace App\Domain\Platform\Models;

use App\Domain\Platform\Enums\BackupStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Un passage de la sauvegarde de nuit (02-TRD §12.3) : donnée de la plateforme, sans organisation. Écrit par
 * RunBackup seulement.
 *
 * @property string $id
 * @property BackupStatus $status
 * @property CarbonInterface $started_at
 * @property CarbonInterface|null $finished_at
 * @property string|null $database_file
 * @property int|null $database_bytes
 * @property string|null $files_file
 * @property string|null $files_level
 * @property int|null $files_bytes
 * @property bool $remote_copied
 * @property string|null $error
 */
class BackupRun extends Model
{
    use HasUlids;

    /**
     * Écrit par RunBackup seulement.
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
            'status' => BackupStatus::class,
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'database_bytes' => 'integer',
            'files_bytes' => 'integer',
            'remote_copied' => 'boolean',
        ];
    }
}
