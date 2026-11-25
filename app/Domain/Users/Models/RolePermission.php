<?php

namespace App\Domain\Users\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Permissions\PermissionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Droit d'un rôle : paramétrage réseau (sans organisation) ou surcharge d'un CGA (RG-ROLE-01 à 06).
 * Toute modification est journalisée et prend effet immédiatement (RG-ROLE-05).
 *
 * @property int $id
 * @property string|null $organization_id
 * @property Role $role
 * @property string $permission
 * @property bool $granted
 */
class RolePermission extends Model
{
    use Auditable;

    /**
     * Écrit par les Actions de paramétrage des droits seulement, après contrôle du catalogue.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['role' => Role::class, 'granted' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saved(static fn () => app(PermissionResolver::class)->flush());
        static::deleted(static fn () => app(PermissionResolver::class)->flush());
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
