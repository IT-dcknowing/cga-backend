<?php

namespace App\Domain\Audit\Models;

use App\Domain\Audit\Enums\ActivityResult;
use Illuminate\Database\Eloquent\Model;

/**
 * Ligne du journal d'activité (RG-SEC-05, 02-TRD §6.5). Écrite par ActivityLogger, jamais modifiée
 * ni supprimée par l'application.
 *
 * @property int $id
 * @property string|null $request_id
 * @property string $actor_type
 * @property string|null $actor_label
 * @property string|null $user_id
 * @property string|null $membership_id
 * @property string|null $role
 * @property string|null $organization_id
 * @property string $action
 * @property string|null $method
 * @property string|null $route
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed>|null $params
 * @property ActivityResult $result
 * @property int|null $http_status
 * @property int|null $duration_ms
 * @property string|null $error_code
 * @property string|null $ip
 * @property string|null $user_agent
 */
class ActivityLog extends Model
{
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
            'params' => 'array',
            'result' => ActivityResult::class,
            'http_status' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
