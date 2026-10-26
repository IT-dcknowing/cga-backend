<?php

namespace App\Domain\Documents\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fichier stocké par StorageService sur le disque du serveur (02-TRD §6.4) : la base ne garde que
 * son emplacement, ses métadonnées et son empreinte SHA-256.
 *
 * @property string $id
 * @property string|null $organization_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 */
class File extends Model
{
    use Auditable, HasUlids;

    public const UPDATED_AT = null;

    /**
     * Enregistré par StorageService seulement, à partir du fichier lui-même.
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
            'size_bytes' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
