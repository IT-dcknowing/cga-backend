<?php

namespace App\Domain\Users\Models;

use App\Domain\Users\Enums\UserStatus;
use Database\Factories\UserFactory;
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
 * @property UserStatus $status
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUlids, Notifiable;

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

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
