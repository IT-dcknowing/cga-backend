<?php

namespace App\Domain\Audit\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Ligne du journal des modifications (RG-SEC-05, 02-TRD §6.5). Jamais modifiée ni supprimée par
 * l'application : seule la purge de rétention retire des partitions entières.
 *
 * @property int $id
 * @property string|null $organization_id
 * @property string|null $user_id
 * @property string|null $membership_id
 * @property string $event
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, array{0: mixed, 1: mixed}>|null $changes
 * @property array<string, mixed>|null $context
 * @property string|null $request_id
 * @property string|null $ip
 * @property string|null $user_agent
 * @property CarbonImmutable $created_at
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * Écrit par le journal seulement (trait Auditable, Actions).
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
            'changes' => 'array',
            'context' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
