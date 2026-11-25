<?php

namespace App\Domain\Announcements\Enums;

use App\Support\Enums\HasLabel;

/**
 * Cible d'une annonce (PRD M12) : du CNCGA, tous les CGA, certains CGA choisis ou tous les adhérents du réseau
 * (F-COM-01) ; d'un CGA, ses adhérents, tous ou filtrés (F-COM-02).
 */
enum AnnouncementAudience: string
{
    use HasLabel;

    case AllCgas = 'all_cgas';
    case SelectedCgas = 'selected_cgas';
    case AllNetworkMembers = 'all_network_members';
    case OwnMembers = 'own_members';

    /**
     * Cibles d'une annonce du CNCGA.
     *
     * @return list<self>
     */
    public static function network(): array
    {
        return [self::AllCgas, self::SelectedCgas, self::AllNetworkMembers];
    }
}
