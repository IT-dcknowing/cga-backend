<?php

namespace App\Domain\Users\Permissions;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\RolePermission;
use App\Domain\Users\Models\UserPermissionOverride;
use App\Support\Tenancy\OrganizationScope;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Collection;

/**
 * Droits effectifs d'un rattachement (02-TRD §6.2), dans cet ordre :
 *  1. permission verrouillée : valeur du catalogue, toujours (RG-ROLE-04) ;
 *  2. sinon, ajustement individuel (RG-ROLE-07) ;
 *  3. sinon, surcharge du CGA pour le rôle (RG-ROLE-03) ;
 *  4. sinon, paramétrage réseau (RG-ROLE-02), et à défaut la matrice du catalogue.
 * Les rôles d'un même utilisateur dans une organisation s'additionnent ; un retrait individuel
 * l'emporte. Résultat mis en cache par rattachement, invalidé à toute modification (flush()).
 */
class PermissionResolver
{
    public const ORIGIN_LOCKED = 'locked';

    public const ORIGIN_USER = 'user';

    public const ORIGIN_ORGANIZATION = 'organization';

    public const ORIGIN_NETWORK = 'network';

    private const VERSION_KEY = 'permissions:version';

    public function __construct(
        private readonly PermissionCatalog $catalog,
        private readonly Cache $cache,
    ) {}

    public function allows(Membership $membership, string $permission): bool
    {
        if (! $this->catalog->has($permission)) {
            throw new UnknownPermissionException($permission);
        }

        return $this->resolve($membership)[$permission];
    }

    /**
     * @return array<string, bool> permission => accordée
     */
    public function resolve(Membership $membership): array
    {
        return $this->cache->rememberForever(
            "permissions:{$this->version()}:{$membership->getKey()}",
            fn (): array => array_map(static fn (array $entry): bool => $entry['granted'], $this->explain($membership)),
        );
    }

    /**
     * Valeur et origine de chaque droit (écran des habilitations, GET /cga/users/{id}/permissions).
     * Sans les ajustements individuels : le profil de l'utilisateur, référence de ses cases.
     *
     * @return array<string, array{granted: bool, origin: string}>
     */
    public function explain(Membership $membership, bool $withOverrides = true): array
    {
        $memberships = $this->sameSpaceMemberships($membership);
        $roles = $memberships->map(static fn (Membership $m): Role => $m->role)->unique()->values();
        $overrides = ! $withOverrides ? collect() : UserPermissionOverride::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $membership->organization_id)
            ->whereIn('membership_id', $memberships->map(static fn (Membership $m): string => $m->getKey())->all())
            ->get()
            ->groupBy('permission');
        $rows = $this->rolePermissions($membership, $roles);

        $result = [];
        foreach ($this->catalog->codes() as $code) {
            $result[$code] = match (true) {
                $this->catalog->isLocked($code) => [
                    'granted' => $roles->contains(fn (Role $role): bool => $this->catalog->grantsByDefault($role, $code)),
                    'origin' => self::ORIGIN_LOCKED,
                ],
                $overrides->has($code) => [
                    'granted' => $overrides->get($code)->every(static fn (UserPermissionOverride $o): bool => $o->granted),
                    'origin' => self::ORIGIN_USER,
                ],
                default => $this->fromRoles($code, $roles, $rows),
            };
        }

        return $result;
    }

    /**
     * Invalide les droits en cache de tous les rattachements (rôle, surcharge ou ajustement modifié).
     */
    public function flush(): void
    {
        $this->cache->forever(self::VERSION_KEY, $this->version() + 1);
    }

    private function version(): int
    {
        return (int) $this->cache->get(self::VERSION_KEY, 1);
    }

    /**
     * Rattachements du même utilisateur dans la même organisation (et pour le même adhérent).
     *
     * @return Collection<int, Membership>
     */
    private function sameSpaceMemberships(Membership $membership): Collection
    {
        $others = Membership::query()
            ->where('user_id', $membership->user_id)
            ->where('organization_id', $membership->organization_id)
            ->where(fn ($query) => $membership->member_id === null
                ? $query->whereNull('member_id')
                : $query->where('member_id', $membership->member_id))
            ->whereIn('status', [MembershipStatus::Active, MembershipStatus::ReadOnly])
            ->get();

        return $others->push($membership)->unique(static fn (Membership $m): string => $m->getKey())->values();
    }

    /**
     * Lignes réseau des rôles, et surcharges du CGA pour ses rôles paramétrables (cga_* hors gérant).
     *
     * @param  Collection<int, Role>  $roles
     * @return array<string, array<string, array<string, bool>>> rôle => permission => [niveau => accordée]
     */
    private function rolePermissions(Membership $membership, Collection $roles): array
    {
        $membership->loadMissing('organization');
        $cgaRoles = $membership->organization->type === OrganizationType::Cga
            ? $roles->filter(fn (Role $role): bool => $this->cgaMayOverride($role))->map->value->all()
            : [];

        $rows = RolePermission::query()
            ->whereIn('role', $roles->map->value->all())
            ->where(fn ($query) => $query
                ->whereNull('organization_id')
                ->orWhere(fn ($cga) => $cga->where('organization_id', $membership->organization_id)->whereIn('role', $cgaRoles)))
            ->get();

        $index = [];
        foreach ($rows as $row) {
            $level = $row->organization_id === null ? self::ORIGIN_NETWORK : self::ORIGIN_ORGANIZATION;
            $index[$row->role->value][$row->permission][$level] = $row->granted;
        }

        return $index;
    }

    /**
     * @param  Collection<int, Role>  $roles
     * @param  array<string, array<string, array<string, bool>>>  $rows
     * @return array{granted: bool, origin: string}
     */
    private function fromRoles(string $code, Collection $roles, array $rows): array
    {
        $origin = self::ORIGIN_NETWORK;

        foreach ($roles as $role) {
            $levels = $rows[$role->value][$code] ?? [];
            [$granted, $level] = match (true) {
                array_key_exists(self::ORIGIN_ORGANIZATION, $levels) => [$levels[self::ORIGIN_ORGANIZATION], self::ORIGIN_ORGANIZATION],
                array_key_exists(self::ORIGIN_NETWORK, $levels) => [$levels[self::ORIGIN_NETWORK], self::ORIGIN_NETWORK],
                default => [$this->catalog->grantsByDefault($role, $code), self::ORIGIN_NETWORK],
            };

            if ($granted) {
                return ['granted' => true, 'origin' => $level];
            }

            if ($level === self::ORIGIN_ORGANIZATION) {
                $origin = self::ORIGIN_ORGANIZATION;
            }
        }

        return ['granted' => false, 'origin' => $origin];
    }

    /**
     * Un CGA ajuste les droits de ses rôles, sauf ceux du gérant (RG-ROLE-03, 05-BACKEND-SCHEMA §2).
     */
    public function cgaMayOverride(Role $role): bool
    {
        return $role->organizationType() === OrganizationType::Cga && ! $role->isMemberRole() && $role !== Role::CgaManager;
    }
}
