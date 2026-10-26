<?php

namespace App\Domain\Members\Support;

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Models\Organization;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Collection;

/**
 * Nombre d'adhérents de chaque CGA vu du réseau (registre du CNCGA) : le portefeuille CGA seulement,
 * dossiers actifs ou en radiation (RG-PTF-02, RG-SEC-08) ; le portefeuille Cabinet n'est jamais
 * compté. Lecture agrégée dans les autres CGA, par un contournement journalisé.
 */
class RegulatoryMemberCounts
{
    /**
     * Range le nombre dans l'attribut members_count de chaque organisation, en une requête.
     *
     * @param  Collection<int, Organization>  $organizations
     */
    public function load(Collection $organizations): void
    {
        if ($organizations->isEmpty()) {
            return;
        }

        $counts = Tenancy::bypass(static fn (): Collection => Member::query()
            ->regulatory()
            ->whereIn('status', [MemberStatus::Active, MemberStatus::RadiationPending])
            ->whereIn('organization_id', $organizations->map(static fn (Organization $organization): string => $organization->id)->all())
            ->groupBy('organization_id')
            ->selectRaw('organization_id, count(*) as total')
            ->pluck('total', 'organization_id'), 'Registre des CGA : nombre d\'adhérents du portefeuille CGA de chaque centre (RG-SEC-08).');

        $organizations->each(static fn (Organization $organization) => $organization->setAttribute('members_count', (int) ($counts[$organization->id] ?? 0)));
    }
}
