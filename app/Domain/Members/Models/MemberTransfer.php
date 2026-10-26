<?php

namespace App\Domain\Members\Models;

use App\Domain\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Réimport des données d'identification d'un adhérent depuis un autre CGA, avec son accord écrit
 * (RG-ADH-15). Relie deux CGA : hors cloisonnement standard, lu et écrit par une Action dédiée
 * journalisée seulement.
 *
 * @property string $id
 * @property string $from_member_id
 * @property string $to_organization_id
 * @property string|null $to_member_id
 * @property string $consent_file_id
 * @property string $requested_by
 */
class MemberTransfer extends Model
{
    use Auditable, HasUlids;

    public const UPDATED_AT = null;

    /**
     * Écrit par les Actions du dossier seulement.
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
            'created_at' => 'immutable_datetime',
        ];
    }
}
