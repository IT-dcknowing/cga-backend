<?php

namespace App\Domain\Users\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Users\Permissions\PermissionResolver;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Habilitation individuelle : un droit accordé en plus du profil ou retiré (RG-ROLE-07). Donnée de
 * l'organisation du rattachement, cloisonnée.
 *
 * @property int $id
 * @property string $organization_id
 * @property string $membership_id
 * @property string $permission
 * @property bool $granted
 */
class UserPermissionOverride extends Model
{
    use Auditable, BelongsToOrganization;

    /**
     * Écrit par l'Action des habilitations seulement (étape 1.11).
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['granted' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saved(static fn () => app(PermissionResolver::class)->flush());
        static::deleted(static fn () => app(PermissionResolver::class)->flush());
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }
}
