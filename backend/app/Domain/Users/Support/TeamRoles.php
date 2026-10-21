<?php

namespace App\Domain\Users\Support;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Users\Enums\Role;

/**
 * Équipe d'une organisation (F-ORG-09, F-ORG-10) : rôles que son responsable attribue à ses agents,
 * droit qui permet de les gérer, et responsable lui-même, qu'il ne gère pas (le gérant d'un CGA est
 * invité par le CNCGA, F-ORG-02 ; la Présidente par l'administrateur de la plateforme).
 */
final class TeamRoles
{
    /**
     * @return list<Role>
     */
    public static function assignable(OrganizationType $type): array
    {
        return match ($type) {
            OrganizationType::Cga => [
                Role::CgaAccountantExpert, Role::CgaAccountant, Role::CgaLegal, Role::CgaTaxAdvisor,
                Role::CgaBillingOfficer, Role::CgaTrainer, Role::CgaSecretary,
            ],
            OrganizationType::Cncga => [Role::CncgaManager, Role::CncgaDues, Role::CncgaTraining, Role::CncgaViewer],
            OrganizationType::Platform => [],
        };
    }

    public static function head(OrganizationType $type): ?Role
    {
        return match ($type) {
            OrganizationType::Cga => Role::CgaManager,
            OrganizationType::Cncga => Role::CncgaPresident,
            OrganizationType::Platform => null,
        };
    }

    public static function permission(OrganizationType $type): ?string
    {
        return match ($type) {
            OrganizationType::Cga => 'cga_users.manage',
            OrganizationType::Cncga => 'network_users.manage',
            OrganizationType::Platform => null,
        };
    }
}
