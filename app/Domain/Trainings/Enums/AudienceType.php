<?php

namespace App\Domain\Trainings\Enums;

use App\Support\Enums\HasLabel;

/**
 * Public d'une formation (F-FOR-01) : responsables, agents ou comptables des CGA, adhérents (tous, d'un régime, nouveaux). Un CGA ne cible que ses propres agents et adhérents (RG-FOR-01).
 */
enum AudienceType: string
{
    use HasLabel;

    case CgaManagers = 'cga_managers';
    case CgaStaff = 'cga_staff';
    case CgaAccountants = 'cga_accountants';
    case AllMembers = 'all_members';
    case MembersByRegime = 'members_by_regime';
    case NewMembers = 'new_members';
}
