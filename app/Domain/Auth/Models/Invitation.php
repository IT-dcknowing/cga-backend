<?php

namespace App\Domain\Auth\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Auth\Enums\InvitationChannel;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\UserStatus;
use App\Domain\Users\Models\Membership;
use Carbon\CarbonInterface;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Invitation à activer un rattachement (F-AUTH-02). Seule l'empreinte SHA-256 du jeton est gardée.
 *
 * @property string $id
 * @property string $membership_id
 * @property string $token_hash
 * @property InvitationChannel $channel
 * @property CarbonInterface $sent_at
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $accepted_at
 * @property CarbonInterface|null $revoked_at
 * @property-read Membership $membership
 */
class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use Auditable, HasFactory, HasUlids;

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
     * Empreinte SHA-256 du jeton envoyé dans le lien (02-TRD §6.1) : seule valeur gardée en base.
     */
    public static function fingerprint(#[\SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Le lien permet encore d'activer l'accès : ni accepté, ni révoqué, ni expiré, rattachement
     * toujours en attente, compte ni désactivé ni supprimé, organisation non résiliée (F-AUTH-02).
     */
    public function isUsable(): bool
    {
        $membership = $this->membership;

        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture()
            && $membership->status === MembershipStatus::Invited
            && in_array($membership->user->status, [UserStatus::Invited, UserStatus::Active], true)
            && $membership->organization->status !== OrganizationStatus::Terminated;
    }

    /**
     * Contact de l'organisation qui invite, affiché avec « Ce lien n'est plus valide » (F-AUTH-02).
     */
    public function organizationContact(): string
    {
        $organization = $this->membership->organization;

        return implode(' · ', array_filter([$organization->phone_1, $organization->email]));
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    protected static function newFactory(): InvitationFactory
    {
        return InvitationFactory::new();
    }
}
