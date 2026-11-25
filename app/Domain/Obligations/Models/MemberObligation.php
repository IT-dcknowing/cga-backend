<?php

namespace App\Domain\Obligations\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Members\Models\Member;
use App\Domain\Obligations\Enums\MemberObligationSource;
use App\Domain\Obligations\Enums\MemberObligationStatus;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonInterface;
use Database\Factories\MemberObligationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Obligation attribuée à un adhérent (F-OBL-02) : proposée par la plateforme (RG-OBL-02, 03) puis
 * confirmée par le CGA, ou ajoutée à la main ; retirée sans être supprimée (son historique reste).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $member_id
 * @property string $obligation_type_id
 * @property MemberObligationSource $source
 * @property MemberObligationStatus $status
 * @property string|null $confirmed_by
 * @property CarbonInterface|null $confirmed_at
 * @property CarbonInterface $start_date
 * @property CarbonInterface|null $end_date
 * @property-read Member $member
 * @property-read ObligationType $type
 */
class MemberObligation extends Model
{
    /** @use HasFactory<MemberObligationFactory> */
    use Auditable, BelongsToOrganization, HasFactory, HasUlids;

    /**
     * Écrit par les Actions des obligations seulement.
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
            'source' => MemberObligationSource::class,
            'status' => MemberObligationStatus::class,
            'confirmed_at' => 'immutable_datetime',
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
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
     * @return BelongsTo<ObligationType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(ObligationType::class, 'obligation_type_id');
    }

    /**
     * @return HasMany<ObligationInstance, $this>
     */
    public function instances(): HasMany
    {
        return $this->hasMany(ObligationInstance::class);
    }

    protected static function newFactory(): MemberObligationFactory
    {
        return MemberObligationFactory::new();
    }
}
