<?php

namespace App\Domain\Notifications\Support;

use App\Domain\Notifications\EventNotification;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Permissions\PermissionResolver;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Collection;

/**
 * Destinataires des notifications (PRD M13) et envoi : chaque destinataire est un rattachement — la
 * notification s'affiche dans cet espace — dont l'utilisateur reçoit les e-mails, SMS et WhatsApp.
 * Accès en ligne d'un dossier (y compris en consultation seule), agents actifs d'un centre selon leurs
 * droits effectifs (PermissionResolver) ou leur rôle, agents du CNCGA. Lecture hors espace actif par un
 * contournement nommé (tâches planifiées, écouteurs).
 */
final class Recipients
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    /**
     * @return Collection<int, Membership>
     */
    public function memberAccesses(string $memberId): Collection
    {
        return Tenancy::bypass(static fn (): Collection => Membership::query()
            ->with('user')
            ->where('member_id', $memberId)
            ->whereIn('status', [MembershipStatus::Active, MembershipStatus::ReadOnly])
            ->get(), 'Notifications : accès en ligne d\'un dossier.');
    }

    /**
     * Agents actifs d'un centre qui ont ce droit.
     *
     * @return Collection<int, Membership>
     */
    public function agents(string $organizationId, string $permission): Collection
    {
        return $this->staff($organizationId)->filter(fn (Membership $membership): bool => $this->permissions->allows($membership, $permission))->values();
    }

    /**
     * Agents actifs d'un centre qui ont l'un de ces rôles.
     *
     * @param  list<Role>  $roles
     * @return Collection<int, Membership>
     */
    public function roles(string $organizationId, array $roles): Collection
    {
        return $this->staff($organizationId)->filter(static fn (Membership $membership): bool => in_array($membership->role, $roles, true))->values();
    }

    /**
     * Agents actifs du CNCGA qui ont ce droit.
     *
     * @return Collection<int, Membership>
     */
    public function network(string $permission): Collection
    {
        $cncga = Tenancy::bypass(static fn (): ?string => Organization::query()->where('type', OrganizationType::Cncga)->value('id'), 'Notifications : CNCGA.');

        return $cncga === null ? new Collection : $this->agents($cncga, $permission);
    }

    /**
     * Envoie la notification à chaque destinataire, une seule fois par rattachement.
     *
     * @param  iterable<Membership>  $recipients
     * @param  array<string, string|int>  $values
     * @return int destinataires notifiés
     */
    public function notify(iterable $recipients, string $type, array $values = [], ?string $path = null): int
    {
        $sent = [];
        foreach ($recipients as $membership) {
            if (isset($sent[$membership->id])) {
                continue;
            }
            $membership->user->notify(new EventNotification($membership->organization_id, $membership->id, $type, $values, $path));
            $sent[$membership->id] = true;
        }

        return count($sent);
    }

    /**
     * @return Collection<int, Membership>
     */
    private function staff(string $organizationId): Collection
    {
        return Tenancy::bypass(static fn (): Collection => Membership::query()
            ->with(['user', 'organization'])
            ->where('organization_id', $organizationId)
            ->whereNull('member_id')
            ->where('status', MembershipStatus::Active)
            ->get(), 'Notifications : agents d\'un centre.');
    }
}
