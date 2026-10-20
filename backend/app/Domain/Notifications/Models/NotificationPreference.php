<?php

namespace App\Domain\Notifications\Models;

use App\Domain\Notifications\Enums\PreferenceChannel;
use App\Domain\Users\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Choix d'un utilisateur pour un canal : refus des messages non essentiels et date du consentement
 * (RG-NOT-04). Clé composée (utilisateur, canal) : le modèle sert à la lecture ; l'écriture passe par
 * SetNotificationPreferences, qui l'inscrit au journal des modifications.
 *
 * @property string $user_id
 * @property PreferenceChannel $channel
 * @property bool $opted_out
 * @property CarbonInterface|null $consented_at
 */
class NotificationPreference extends Model
{
    public $timestamps = false;

    public $incrementing = false;

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
            'channel' => PreferenceChannel::class,
            'opted_out' => 'boolean',
            'consented_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(static fn (): never => throw new LogicException('Les préférences s\'écrivent par SetNotificationPreferences.'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * L'utilisateur accepte les messages non essentiels de ce canal.
     */
    public function accepts(): bool
    {
        return ! $this->opted_out;
    }
}
