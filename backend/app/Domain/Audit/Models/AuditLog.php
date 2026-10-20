<?php

namespace App\Domain\Audit\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ligne du journal des modifications (RG-SEC-05, 02-TRD §6.5). Jamais modifiée ni supprimée par
 * l'application : seule la purge de rétention retire des partitions entières.
 *
 * @property int $id
 * @property string $event
 * @property array<string, array{0: mixed, 1: mixed}>|null $changes
 * @property array<string, mixed>|null $context
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
