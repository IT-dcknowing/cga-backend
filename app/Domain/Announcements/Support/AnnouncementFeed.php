<?php

namespace App\Domain\Announcements\Support;

use App\Domain\Announcements\Enums\AnnouncementAudience;
use App\Domain\Announcements\Models\Announcement;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Models\Member;
use App\Domain\Notifications\Actions\MessageCampaigns;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Destinataires et lecteurs des annonces (PRD M12) :
 * - du CNCGA « à tous les CGA » ou « à certains CGA » : les agents actifs des CGA actifs visés ;
 * - du CNCGA « à tous les adhérents du réseau » : les accès en ligne actifs des dossiers ouverts du portefeuille
 *   CGA, dans les CGA actifs (le portefeuille Cabinet est interne au centre) ;
 * - d'un CGA à ses adhérents : les accès en ligne actifs des dossiers retenus par son filtre (portefeuille,
 *   régimes, secteurs), le même que celui des envois groupés.
 * Le fil d'un espace reprend ces règles au moment de la lecture. Les annonces d'un autre centre sont lues par un
 * contournement nommé.
 */
final class AnnouncementFeed
{
    public function __construct(private readonly MessageCampaigns $campaigns) {}

    /**
     * Destinataires d'une annonce publiée, avec leur utilisateur.
     *
     * @return Collection<int, Membership>
     */
    public function recipients(Announcement $announcement): Collection
    {
        return Tenancy::bypass(function () use ($announcement): Collection {
            $query = Membership::query()
                ->with('user')
                ->where('status', MembershipStatus::Active)
                ->whereHas('organization', static fn (Builder $organization) => $organization->where('type', OrganizationType::Cga)->where('status', OrganizationStatus::Active));

            return match ($announcement->audience) {
                AnnouncementAudience::AllCgas => $query->whereNull('member_id')->get(),
                AnnouncementAudience::SelectedCgas => $query->whereNull('member_id')->whereIn('organization_id', $announcement->targets()->pluck('target_organization_id'))->get(),
                AnnouncementAudience::AllNetworkMembers => $query->whereIn('member_id', Member::query()->where('portfolio', Portfolio::Cga)->whereIn('status', [MemberStatus::Active, MemberStatus::RadiationPending])->select('id'))->get(),
                AnnouncementAudience::OwnMembers => $query->where('organization_id', $announcement->organization_id)
                    ->whereIn('member_id', $this->campaigns->members($announcement->organization_id, [...self::filter($announcement), 'member_ids' => []])->select('id'))
                    ->get(),
            };
        }, 'Annonces : destinataires d\'une annonce.');
    }

    /**
     * Annonces reçues dans l'espace de ce rattachement (à appeler dans un contournement) : pour un agent, celles du
     * CNCGA à son CGA ; pour un adhérent, celles de son CGA qui le retiennent et celles du CNCGA au réseau.
     *
     * @return Builder<Announcement>
     */
    public function received(Membership $viewer): Builder
    {
        $query = Announcement::query();
        if ($viewer->member_id === null) {
            return $query
                ->whereHas('organization', static fn (Builder $organization) => $organization->where('type', OrganizationType::Cncga))
                ->where(static fn (Builder $audience) => $audience
                    ->where('audience', AnnouncementAudience::AllCgas)
                    ->orWhere(static fn (Builder $selected) => $selected
                        ->where('audience', AnnouncementAudience::SelectedCgas)
                        ->whereHas('targets', static fn (Builder $target) => $target->where('target_organization_id', $viewer->organization_id))));
        }

        $member = Member::query()->find($viewer->member_id);
        if ($member === null || ! in_array($member->status, [MemberStatus::Active, MemberStatus::RadiationPending], true)) {
            return $query->whereKey([]);
        }

        return $query->where(static function (Builder $any) use ($member): void {
            $any->where(static fn (Builder $own) => $own
                ->where('organization_id', $member->organization_id)
                ->where('audience', AnnouncementAudience::OwnMembers)
                ->where(static fn (Builder $portfolio) => $portfolio->where('member_filter->portfolio', 'all')->orWhere('member_filter->portfolio', $member->portfolio->value))
                ->where(static fn (Builder $regimes) => $regimes->whereJsonLength('member_filter->regimes', 0)->orWhereJsonContains('member_filter->regimes', $member->regime->value))
                ->where(static fn (Builder $sectors) => $sectors->whereJsonLength('member_filter->sector_ids', 0)->orWhereJsonContains('member_filter->sector_ids', (string) $member->sector_id)));
            if ($member->portfolio === Portfolio::Cga) {
                $any->orWhere(static fn (Builder $network) => $network
                    ->where('audience', AnnouncementAudience::AllNetworkMembers)
                    ->whereHas('organization', static fn (Builder $organization) => $organization->where('type', OrganizationType::Cncga)));
            }
        });
    }

    /**
     * @return array{portfolio: string, regimes: list<string>, sector_ids: list<string>}
     */
    public static function filter(Announcement $announcement): array
    {
        return $announcement->member_filter ?? ['portfolio' => Portfolio::Cga->value, 'regimes' => [], 'sector_ids' => []];
    }
}
