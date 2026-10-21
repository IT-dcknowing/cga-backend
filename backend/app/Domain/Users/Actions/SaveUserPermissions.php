<?php

namespace App\Domain\Users\Actions;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Domain\Users\Models\UserPermissionOverride;
use App\Domain\Users\Permissions\UserPermissionCloud;
use App\Support\Http\Exceptions\BusinessRuleException;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Habilitations individuelles d'un agent (RG-ROLE-07) : la liste donnée est celle des droits qu'il
 * doit avoir ; chaque écart à son profil devient un ajustement (accordé en plus ou retiré), un droit
 * égal au profil n'en garde aucun. Les droits verrouillés et ceux hors de ses rôles sont ignorés
 * (RG-ROLE-04). L'ajustement vaut pour tous ses rôles dans l'organisation ; il est journalisé et
 * s'applique aussitôt (RG-ROLE-05). Nul ne modifie ses propres habilitations.
 */
class SaveUserPermissions
{
    public function __construct(
        private readonly UserPermissionCloud $cloud,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  list<string>  $granted
     *
     * @throws BusinessRuleException cannot_manage_self
     */
    public function execute(Organization $organization, User $agent, array $granted, ?string $reason, User $actor): void
    {
        if ($agent->is($actor)) {
            throw new BusinessRuleException('cannot_manage_self');
        }

        $this->inOrganization($organization, function () use ($organization, $agent, $granted, $reason, $actor): void {
            DB::transaction(function () use ($organization, $agent, $granted, $reason, $actor): void {
                $memberships = $this->cloud->memberships($organization, $agent);

                foreach ($this->cloud->of($organization, $agent) as $code => $permission) {
                    if ($permission['locked']) {
                        continue;
                    }

                    $wanted = in_array($code, $granted, true);
                    foreach ($memberships as $membership) {
                        $wanted === $permission['profile']
                            ? $this->override($membership, $code)?->delete()
                            : $this->write($membership, $code, $wanted, $reason, $actor);
                    }
                }
            });
        });
    }

    /**
     * Les ajustements appartiennent à l'organisation de l'agent ; l'administrateur de la plateforme, qui
     * les règle pour le CNCGA depuis son espace, passe par un contournement journalisé (02-TRD §6.3).
     *
     * @param  callable(): void  $callback
     */
    public function inOrganization(Organization $organization, callable $callback): void
    {
        $this->context->organizationId() === $organization->id
            ? $callback()
            : Tenancy::bypass($callback, 'Habilitations d\'un utilisateur du CNCGA par l\'administrateur de la plateforme (RG-ROLE-07).');
    }

    private function override(Membership $membership, string $code): ?UserPermissionOverride
    {
        return UserPermissionOverride::query()->where('membership_id', $membership->id)->where('permission', $code)->lockForUpdate()->first();
    }

    private function write(Membership $membership, string $code, bool $granted, ?string $reason, User $actor): void
    {
        $override = $this->override($membership, $code) ?? new UserPermissionOverride;
        if ($override->exists && $override->granted === $granted) {
            return;
        }

        $override->organization_id = $membership->organization_id;
        $override->membership_id = $membership->id;
        $override->permission = $code;
        $override->granted = $granted;
        $override->setAttribute('reason', $reason);
        $override->setAttribute('updated_by', $actor->id);
        $override->save();
    }
}
