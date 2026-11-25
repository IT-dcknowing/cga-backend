<?php

namespace App\Domain\Users\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Auth\Models\Invitation;
use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Enums\SubscriptionStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Permissions\PermissionResolver;
use Carbon\CarbonInterface;
use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rattachement d'un utilisateur à un espace : une organisation, un rôle, et l'adhérent pour un accès
 * adhérent (RG-ACC-02). Un utilisateur peut cumuler plusieurs rôles dans une organisation.
 *
 * @property string $id
 * @property string $user_id
 * @property string $organization_id
 * @property string|null $member_id
 * @property Role $role
 * @property MembershipStatus $status
 * @property CarbonInterface|null $read_only_until
 * @property CarbonInterface|null $activated_at
 * @property CarbonInterface|null $disabled_at
 * @property string|null $invited_by
 * @property-read User $user
 * @property-read Organization $organization
 */
class Membership extends Model
{
    /** @use HasFactory<MembershipFactory> */
    use Auditable, HasFactory, HasUlids;

    /**
     * Organisation, rôle, adhérent et statut sont fixés par les Actions (02-TRD §6.7) : aucun champ
     * n'est remplissable en masse.
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
            'role' => Role::class,
            'status' => MembershipStatus::class,
            'read_only_until' => 'immutable_date',
            'activated_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
        ];
    }

    /**
     * Un rôle ou un statut modifié change les droits : ceux en cache sont invalidés (RG-ROLE-05).
     */
    protected static function booted(): void
    {
        static::saved(static fn () => app(PermissionResolver::class)->flush());
        static::deleted(static fn () => app(PermissionResolver::class)->flush());
    }

    /**
     * Rattachements qui ouvrent un espace : actifs, ou en consultation seule (adhérent radié,
     * RG-ADH-12), dans une organisation non résiliée (02-TRD §6.2) ; pour un CGA, abonné ou à
     * l'essai : un CGA non abonné n'a aucun accès (RG-ORG-06).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function usable(Builder $query): void
    {
        $query->whereIn('status', [MembershipStatus::Active, MembershipStatus::ReadOnly])
            ->whereHas('organization', static function (Builder $organization): void {
                $organization->where('status', '!=', OrganizationStatus::Terminated)
                    ->where(static function (Builder $access): void {
                        $access->where('type', '!=', OrganizationType::Cga)
                            ->orWhereIn('subscription_status', [SubscriptionStatus::Trial, SubscriptionStatus::Active]);
                    });
            });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Adhérent d'un accès adhérent (member_owner, member_user).
     *
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * @return HasMany<Invitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    protected static function newFactory(): MembershipFactory
    {
        return MembershipFactory::new();
    }
}
