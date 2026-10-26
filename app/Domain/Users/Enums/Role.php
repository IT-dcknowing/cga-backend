<?php

namespace App\Domain\Users\Enums;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Support\Enums\HasLabel;

/**
 * Rôles fixes de la plateforme (01-PRD §3.1) ; leurs droits sont paramétrables (role_permissions).
 */
enum Role: string
{
    use HasLabel;

    case PlatformAdmin = 'platform_admin';
    case CncgaPresident = 'cncga_president';
    case CncgaManager = 'cncga_manager';
    case CncgaDues = 'cncga_dues';
    case CncgaTraining = 'cncga_training';
    case CncgaViewer = 'cncga_viewer';
    case CgaManager = 'cga_manager';
    case CgaAccountantExpert = 'cga_accountant_expert';
    case CgaAccountant = 'cga_accountant';
    case CgaLegal = 'cga_legal';
    case CgaTaxAdvisor = 'cga_tax_advisor';
    case CgaBillingOfficer = 'cga_billing_officer';
    case CgaTrainer = 'cga_trainer';
    case CgaSecretary = 'cga_secretary';
    case MemberOwner = 'member_owner';
    case MemberUser = 'member_user';

    /**
     * Type de l'organisation à laquelle le rôle rattache. Un accès adhérent rattache à son CGA.
     */
    public function organizationType(): OrganizationType
    {
        return match (true) {
            $this === self::PlatformAdmin => OrganizationType::Platform,
            str_starts_with($this->value, 'cncga_') => OrganizationType::Cncga,
            default => OrganizationType::Cga,
        };
    }

    /**
     * Accès d'un adhérent : le rattachement porte alors l'adhérent concerné (member_id).
     */
    public function isMemberRole(): bool
    {
        return str_starts_with($this->value, 'member_');
    }
}
