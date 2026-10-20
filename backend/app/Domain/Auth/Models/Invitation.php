<?php

namespace App\Domain\Auth\Models;

use App\Domain\Auth\Enums\InvitationChannel;
use App\Domain\Users\Models\Membership;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Invitation à activer un rattachement (F-AUTH-02). Seule l'empreinte SHA-256 du jeton est gardée.
 *
 * @property string $id
 * @property string $membership_id
 * @property string $token_hash
 * @property InvitationChannel $channel
 */
class Invitation extends Model
{
    use HasUlids;

    /**
     * Créée et consommée par les Actions d'invitation seulement.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var list<string>
     */
    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => InvitationChannel::class,
            'sent_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }
}
