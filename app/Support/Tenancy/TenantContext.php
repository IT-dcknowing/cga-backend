<?php

namespace App\Support\Tenancy;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Permissions\PermissionResolver;

/**
 * Espace actif de la requête (02-TRD §6.2) : le rattachement choisi par l'utilisateur, donc son
 * organisation, son type, son rôle et l'adhérent pour un accès adhérent. Alimenté par ResolveTenant,
 * lu par le cloisonnement (BelongsToOrganization) et les Policies.
 */
class TenantContext
{
    /**
     * Clé de session du rattachement actif (POST /api/v1/me/space).
     */
    public const SESSION_KEY = 'tenancy.membership_id';

    private ?Membership $membership = null;

    public function __construct(private readonly PermissionResolver $permissions) {}

    public function set(Membership $membership): void
    {
        $membership->loadMissing('organization');
        $this->membership = $membership;
    }

    public function clear(): void
    {
        $this->membership = null;
    }

    /**
     * Exécute un traitement dans l'espace de ce rattachement (Job lancé par un utilisateur, qui en
     * reprend l'espace), puis rétablit l'espace d'avant.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAs(Membership $membership, callable $callback): mixed
    {
        $previous = $this->membership;
        $this->set($membership);

        try {
            return $callback();
        } finally {
            $this->membership = $previous;
        }
    }

    public function has(): bool
    {
        return $this->membership !== null;
    }

    /**
     * @throws MissingTenantContextException sans espace actif
     */
    public function membership(): Membership
    {
        return $this->membership ?? throw new MissingTenantContextException;
    }

    public function organization(): Organization
    {
        return $this->membership()->organization;
    }

    public function organizationId(): string
    {
        return $this->membership()->organization_id;
    }

    public function organizationType(): OrganizationType
    {
        return $this->organization()->type;
    }

    public function role(): Role
    {
        return $this->membership()->role;
    }

    public function memberId(): ?string
    {
        return $this->membership()->member_id;
    }

    public function userId(): string
    {
        return $this->membership()->user_id;
    }

    /**
     * Permission résolue du rattachement actif (verrou → utilisateur → CGA → réseau).
     */
    public function allows(string $permission): bool
    {
        return $this->permissions->allows($this->membership(), $permission);
    }
}
