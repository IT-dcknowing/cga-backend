<?php

namespace App\Domain\Concerns\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Concerns\Enums\ConcernCategory;
use App\Domain\Concerns\Enums\ConcernLevel;
use App\Domain\Concerns\Enums\ConcernStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonInterface;
use Database\Factories\ConcernFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Préoccupation (PRD M10, 05-BACKEND-SCHEMA §12) : demande d'un adhérent à son CGA (member_to_cga) ou
 * d'un CGA au CNCGA (cga_to_cncga), cloisonnée au CGA concerné. Le CNCGA lit les demandes des CGA par un
 * contournement nommé ; il ne voit jamais celles des adhérents, seulement leurs chiffres (RG-PRE-03).
 *
 * @property string $id
 * @property string $organization_id
 * @property ConcernLevel $level
 * @property string|null $member_id
 * @property string $author_user_id
 * @property ConcernCategory $category
 * @property string $subject
 * @property ConcernStatus $status
 * @property string|null $rejection_reason
 * @property string|null $transferred_to_concern_id
 * @property string|null $transferred_from_concern_id
 * @property int $target_response_days
 * @property CarbonInterface|null $first_response_at
 * @property CarbonInterface $last_activity_at
 * @property CarbonInterface|null $awaiting_reply_since
 * @property CarbonInterface|null $stale_alert_sent_at
 * @property CarbonInterface|null $resolved_at
 * @property int $reopen_count
 * @property CarbonInterface $created_at
 * @property-read Organization $organization
 * @property-read Member|null $member
 * @property-read User $author
 * @property-read Concern|null $transferredTo
 * @property-read Collection<int, ConcernMessage> $messages
 */
class Concern extends Model
{
    /** @use HasFactory<ConcernFactory> */
    use Auditable, BelongsToOrganization, HasFactory, HasUlids;

    /**
     * Écrit par les Actions des préoccupations seulement.
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
            'level' => ConcernLevel::class,
            'category' => ConcernCategory::class,
            'status' => ConcernStatus::class,
            'target_response_days' => 'integer',
            'first_response_at' => 'immutable_datetime',
            'last_activity_at' => 'immutable_datetime',
            'awaiting_reply_since' => 'immutable_datetime',
            'stale_alert_sent_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'reopen_count' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
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
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /**
     * Demande au CNCGA créée par le transfert de celle-ci (F-PRE-03).
     *
     * @return BelongsTo<Concern, $this>
     */
    public function transferredTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'transferred_to_concern_id');
    }

    /**
     * @return HasMany<ConcernMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ConcernMessage::class)->orderBy('created_at')->orderBy('id');
    }

    protected static function newFactory(): ConcernFactory
    {
        return ConcernFactory::new();
    }
}
