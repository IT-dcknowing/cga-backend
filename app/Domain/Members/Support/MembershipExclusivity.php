<?php

namespace App\Domain\Members\Support;

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Enums\SubscriptionStatus;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Builder;

/**
 * Exclusivité d'adhésion entre CGA (RG-ADH-05, CR-07) : un contribuable n'est inscrit au portefeuille
 * CGA que d'un seul CGA. La recherche dans les autres CGA abonnés est un contournement du
 * cloisonnement, journalisé, qui ne rend qu'un booléen : jamais le nom de l'autre CGA (RG-SEC-02).
 */
class MembershipExclusivity
{
    /**
     * Le NCC est inscrit au portefeuille CGA d'un autre CGA abonné (actif ou radiation en cours).
     */
    public function enrolledElsewhere(string $ncc, string $organizationId): bool
    {
        return Tenancy::bypass(static fn (): bool => Member::query()
            ->where('ncc', Ncc::normalize($ncc))
            ->where('organization_id', '!=', $organizationId)
            ->where('portfolio', Portfolio::Cga)
            ->whereIn('status', [MemberStatus::Active, MemberStatus::RadiationPending])
            ->whereHas('organization', static fn (Builder $organization) => $organization
                ->where('type', OrganizationType::Cga)
                ->whereIn('subscription_status', [SubscriptionStatus::Trial, SubscriptionStatus::Active]))
            ->exists(), 'Exclusivité d\'adhésion : NCC inscrit dans un autre CGA (RG-ADH-05), réponse oui / non.');
    }
}
