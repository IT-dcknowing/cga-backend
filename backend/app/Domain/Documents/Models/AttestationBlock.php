<?php

namespace App\Domain\Documents\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Documents\Enums\AttestationBlockTrigger;
use App\Domain\Members\Models\Member;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Blocage manuel de l'attestation d'un adhérent (RG-DOC-10) : origine, motif, auteur et date, puis
 * déblocage avec son motif. Historisé : une ligne par blocage, jamais supprimée.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $member_id
 * @property AttestationBlockTrigger $trigger
 * @property string $reason
 * @property string $blocked_by
 * @property CarbonInterface $blocked_at
 * @property string|null $unblocked_by
 * @property CarbonInterface|null $unblocked_at
 * @property string|null $unblock_reason
 * @property-read Member $member
 * @property-read User $blocker
 * @property-read User|null $unblocker
 */
class AttestationBlock extends Model
{
    use Auditable, BelongsToOrganization, HasUlids;

    public $timestamps = false;

    /**
     * Écrit par BlockAttestation et ReleaseAttestationBlock seulement.
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
            'trigger' => AttestationBlockTrigger::class,
            'blocked_at' => 'immutable_datetime',
            'unblocked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function blocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function unblocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unblocked_by');
    }
}
