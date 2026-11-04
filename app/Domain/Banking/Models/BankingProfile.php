<?php

namespace App\Domain\Banking\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Members\Models\Member;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * État courant du consentement bancaire d'un adhérent. Ne contient pas encore de score ni de pièces.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $member_id
 * @property bool $consent_granted
 * @property string|null $consent_version
 * @property string|null $consent_text_sha256
 * @property CarbonInterface|null $consented_at
 * @property string|null $consented_by
 * @property CarbonInterface|null $withdrawn_at
 */
class BankingProfile extends Model
{
    use Auditable, BelongsToOrganization, HasUlids;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'consent_granted' => 'boolean',
            'consented_at' => 'immutable_datetime',
            'withdrawn_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<User, $this> */
    public function consentedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consented_by');
    }

    /** @return HasMany<BankingConsentEvent, $this> */
    public function consentEvents(): HasMany
    {
        return $this->hasMany(BankingConsentEvent::class, 'member_id', 'member_id')
            ->orderBy('occurred_at');
    }
}
