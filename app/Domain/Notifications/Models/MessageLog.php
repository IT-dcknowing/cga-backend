<?php

namespace App\Domain\Notifications\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Envoi externe (e-mail, SMS, WhatsApp) d'une notification ou d'un envoi groupé (02-TRD §10) : canal,
 * destinataire, statut, identifiant et coût chez le fournisseur, erreur. Jamais le texte du message.
 * Écrit par MessageLogger, lu par les écrans d'administration ; purgé à 2 ans.
 *
 * @property int $id
 * @property string|null $organization_id
 * @property string|null $campaign_id
 * @property string|null $notification_type
 * @property string $channel
 * @property string $recipient
 * @property string|null $user_id
 * @property string $status
 * @property string|null $provider_message_id
 * @property int|null $cost_units
 * @property string|null $error
 * @property CarbonInterface|null $sent_at
 * @property CarbonInterface $created_at
 */
class MessageLog extends Model
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
            'cost_units' => 'integer',
            'sent_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
