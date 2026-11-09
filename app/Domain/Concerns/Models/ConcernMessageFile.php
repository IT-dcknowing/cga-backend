<?php

namespace App\Domain\Concerns\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Documents\Models\File;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pièce jointe d'un message de préoccupation (05-BACKEND-SCHEMA §12) : un fichier n'est joint qu'à un
 * seul message, d'où sa clé.
 *
 * @property string $organization_id
 * @property string $message_id
 * @property string $file_id
 * @property-read File $file
 */
class ConcernMessageFile extends Model
{
    use Auditable, BelongsToOrganization;

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'file_id';

    protected $keyType = 'string';

    /**
     * Écrit par les Actions des préoccupations seulement.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return BelongsTo<File, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
