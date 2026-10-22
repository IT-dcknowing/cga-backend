<?php

namespace App\Domain\Documents\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Vérification publique d'un document par son QR code (F-DOC-04, RG-DOC-09) : préfixe de l'adresse IP
 * et navigateur, jamais l'adresse complète.
 *
 * @property int $id
 * @property string $document_id
 * @property string|null $ip_prefix
 * @property string|null $user_agent
 * @property CarbonInterface $verified_at
 */
class DocumentVerification extends Model
{
    public $timestamps = false;

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
            'verified_at' => 'immutable_datetime',
        ];
    }
}
