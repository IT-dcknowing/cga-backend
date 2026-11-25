<?php

namespace App\Domain\Notifications\Models;

use App\Domain\Audit\Concerns\Auditable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Compte d'envoi SMS, WhatsApp ou e-mail paramétré dans l'écran « Envois » (RG-NOT-05, F-ADM-06) :
 * paramètres non secrets en clair, identifiants chiffrés jamais renvoyés par l'API, un seul compte actif
 * par canal. Table de la plateforme, hors cloisonnement des centres.
 *
 * @property string $id
 * @property string $channel sms, whatsapp, email
 * @property string $driver log, orange_ci, http_generic, whatsapp_cloud, smtp
 * @property string $label
 * @property array<string, mixed> $settings
 * @property array<string, string> $secrets
 * @property bool $is_active
 * @property CarbonInterface|null $last_test_at
 * @property string|null $last_test_result
 * @property string|null $updated_by
 */
class MessagingProvider extends Model
{
    use Auditable, HasUlids;

    /**
     * Écrit par les Actions de l'écran « Envois » seulement.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * Les identifiants ne sortent jamais du modèle (journal des modifications compris).
     *
     * @var list<string>
     */
    protected $hidden = ['secrets'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['settings' => '{}', 'is_active' => false];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'secrets' => 'encrypted:array',
            'is_active' => 'boolean',
            'last_test_at' => 'immutable_datetime',
        ];
    }
}
