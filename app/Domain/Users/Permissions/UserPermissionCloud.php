<?php

namespace App\Domain\Users\Permissions;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use LogicException;

/**
 * « Nuage d'habilitations » d'un agent dans une organisation (RG-ROLE-07, F-ADM-05) : les droits qui
 * concernent ses rôles, chacun avec sa valeur effective, son origine (verrou, utilisateur, centre,
 * réseau), la valeur de son profil et la marque « ajusté pour cet utilisateur ».
 */
class UserPermissionCloud
{
    public function __construct(
        private readonly PermissionResolver $resolver,
        private readonly PermissionCatalog $catalog,
        private readonly RoleMatrix $matrix,
    ) {}

    /**
     * Rattachements d'agent de l'utilisateur dans l'organisation (un par rôle).
     *
     * @return list<Membership>
     */
    public function memberships(Organization $organization, User $agent): array
    {
        return array_values($agent->memberships()
            ->where('organization_id', $organization->id)
            ->whereNull('member_id')
            ->orderBy('id')
            ->get()
            ->all());
    }

    /**
     * @return array<string, array{code: string, label: string, module: string, granted: bool, origin: string, profile: bool, adjusted: bool, locked: bool}>
     */
    public function of(Organization $organization, User $agent): array
    {
        $memberships = $this->memberships($organization, $agent);
        $first = $memberships[0] ?? throw new LogicException('Agent sans rattachement dans l\'organisation.');
        $effective = $this->resolver->explain($first);
        $profile = $this->resolver->explain($first, withOverrides: false);

        $codes = [];
        foreach ($memberships as $membership) {
            $codes = [...$codes, ...$this->matrix->relevant($membership->role)];
        }

        $cloud = [];
        foreach (array_unique($codes) as $code) {
            $cloud[$code] = [
                'code' => $code,
                'label' => $this->catalog->label($code),
                'module' => $this->catalog->screenModule($code),
                'granted' => $effective[$code]['granted'],
                'origin' => $effective[$code]['origin'],
                'profile' => $profile[$code]['granted'],
                'adjusted' => $effective[$code]['origin'] === PermissionResolver::ORIGIN_USER,
                'locked' => $this->catalog->isLocked($code),
            ];
        }
        ksort($cloud);

        return $cloud;
    }
}
