<?php

namespace App\Domain\Users\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Notifications\Enums\PreferenceChannel;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Domain\Users\Enums\UserStatus;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Personne physique : un compte par personne, identifié par son téléphone (RG-ACC-01). Ses accès aux
 * espaces sont ses rattachements (memberships).
 *
 * @property string $id
 * @property string $first_name
 * @property string $last_name
 * @property string $phone
 * @property string|null $email
 * @property string|null $password
 * @property UserStatus $status
 * @property CarbonInterface|null $phone_verified_at
 * @property CarbonInterface|null $email_verified_at
 * @property CarbonInterface|null $last_login_at
 * @property string|null $last_space_membership_id
 * @property-read Collection<int, NotificationPreference> $notificationPreferences
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasFactory, HasUlids, Notifiable;

    /**
     * Le statut, le mot de passe et les dates de connexion sont fixés par les Actions, jamais par
     * une saisie (02-TRD §6.7).
     *
     * @var list<string>
     */
    protected $fillable = ['first_name', 'last_name', 'phone', 'email'];

    /**
     * @var list<string>
     */
    protected $hidden = ['password'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => UserStatus::class,
            'password' => 'hashed',
            'phone_verified_at' => 'immutable_datetime',
            'email_verified_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
            'inactivity_notice_sent_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Dernier espace choisi, proposé à la connexion suivante.
     *
     * @return BelongsTo<Membership, $this>
     */
    public function lastSpaceMembership(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'last_space_membership_id');
    }

    /**
     * @return HasMany<NotificationPreference, $this>
     */
    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    /**
     * L'utilisateur accepte les messages non essentiels de ce canal (RG-NOT-04) : seulement avec un
     * consentement enregistré, recueilli à l'activation ou dans le profil.
     */
    public function acceptsMessagesBy(PreferenceChannel $channel): bool
    {
        return $this->notificationPreferences->first(
            static fn (NotificationPreference $preference): bool => $preference->channel === $channel,
        )?->accepts() ?? false;
    }

    /**
     * Numéro du canal « sms » des notifications.
     */
    public function routeNotificationForSms(): string
    {
        return $this->phone;
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
