<?php

namespace App\Domain\Audit\Models;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Accès de l'équipe technique aux données d'un CGA, toujours journalisé (RG-SEC-04).
 *
 * @property int $id
 * @property string $admin_user_id
 * @property string $organization_id
 * @property string $reason
 */
class PlatformAccessLog extends Model
{
    public $timestamps = false;

    /**
     * Écrit par le contournement journalisé du cloisonnement seulement (étape 0.7).
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
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
