<?php

namespace App\Domain\Platform\Models;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Journal des accès techniques de l'équipe support (platform_admin) aux données d'un CGA (RG-SEC-04).
 * Consultable par le gérant du CGA et par l'administrateur de la plateforme.
 *
 * @property int $id
 * @property string $admin_user_id
 * @property string $organization_id
 * @property string $reason
 * @property string|null $route
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $ended_at
 * @property-read User $adminUser
 * @property-read Organization $organization
 */
class PlatformAccessLog extends Model
{
    public $timestamps = false;

    protected $table = 'platform_access_logs';

    protected $fillable = [
        'admin_user_id',
        'organization_id',
        'reason',
        'route',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'started_at' => 'immutable_datetime',
        'ended_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}

