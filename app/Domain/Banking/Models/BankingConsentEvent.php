<?php

namespace App\Domain\Banking\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Members\Models\Member;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Événement historique de consentement bancaire. Les Actions ajoutent des événements sans les réécrire.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $member_id
 * @property string $event
 * @property string $consent_version
 * @property string $consent_text_sha256
 * @property string $actor_user_id
 * @property string|null $membership_id
 * @property CarbonInterface $occurred_at
 */
class BankingConsentEvent extends Model
{
    use Auditable, BelongsToOrganization, HasUlids;

    protected $fillable = [];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Un événement de consentement est immuable.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Un événement de consentement ne peut pas être supprimé.');
        });
    }

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return BelongsTo<Membership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }
}
