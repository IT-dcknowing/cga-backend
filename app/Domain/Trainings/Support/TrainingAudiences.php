<?php

namespace App\Domain\Trainings\Support;

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Trainings\Enums\AudienceType;
use App\Domain\Trainings\Models\Training;
use App\Domain\Trainings\Models\TrainingAudience;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Personnes ciblées par une formation (F-FOR-01, RG-FOR-01, 02) : rattachements actifs des CGA actifs —
 * agents selon les rôles de chaque public (cga.trainings.audience_roles), accès adhérents d'un dossier
 * ouvert du portefeuille CGA (tous, d'un régime, adhésion depuis moins de n mois). Une formation d'un CGA ne
 * cible que ses propres agents et adhérents ; une formation du CNCGA, ceux de tout le réseau. Les dossiers
 * et les publics d'une formation du réseau sont lus par un contournement nommé.
 */
final class TrainingAudiences
{
    /**
     * La personne de ce rattachement est-elle ciblée ?
     */
    public function targets(Training $training, Membership $person): bool
    {
        return Tenancy::bypass(fn (): bool => $this->query($training)->whereKey($person->id)->exists(), 'Formations : public ciblé.');
    }

    /**
     * Rattachements ciblés, avec leur utilisateur et leur organisation, filtrés au besoin.
     *
     * @param  (callable(Builder<Membership>): mixed)|null  $constrain
     * @return Collection<int, Membership>
     */
    public function memberships(Training $training, ?callable $constrain = null): Collection
    {
        return Tenancy::bypass(function () use ($training, $constrain): Collection {
            $query = $this->query($training)->with(['user', 'organization', 'member']);
            if ($constrain !== null) {
                $constrain($query);
            }

            return $query->get();
        }, 'Formations : public ciblé.');
    }

    /**
     * À appeler dans un contournement : les publics et les dossiers d'un autre centre y sont lus.
     *
     * @return Builder<Membership>
     */
    private function query(Training $training): Builder
    {
        /** @var Collection<int, TrainingAudience> $audiences */
        $audiences = TrainingAudience::query()->where('training_id', $training->id)->get();
        $query = Membership::query()
            ->where('memberships.status', MembershipStatus::Active)
            ->whereHas('organization', static fn (Builder $organization) => $organization->where('type', OrganizationType::Cga)->where('status', OrganizationStatus::Active));

        $training->loadMissing('organization');
        if ($training->organization->type === OrganizationType::Cga) {
            $query->where('memberships.organization_id', $training->organization_id);
        }

        if ($audiences->isEmpty()) {
            return $query->whereKey([]);
        }

        return $query->where(function (Builder $any) use ($audiences): void {
            foreach ($audiences as $audience) {
                $any->orWhere(fn (Builder $one) => $this->audience($one, $audience));
            }
        });
    }

    /**
     * @param  Builder<Membership>  $query
     */
    private function audience(Builder $query, TrainingAudience $audience): void
    {
        if (in_array($audience->audience, [AudienceType::CgaManagers, AudienceType::CgaStaff, AudienceType::CgaAccountants], true)) {
            $query->whereNull('member_id')->whereIn('role', (array) config("cga.trainings.audience_roles.{$audience->audience->value}"));

            return;
        }

        $query->whereNotNull('member_id')->whereHas('member', static function (Builder $member) use ($audience): void {
            $member->where('portfolio', Portfolio::Cga)->whereIn('status', [MemberStatus::Active, MemberStatus::RadiationPending]);
            match ($audience->audience) {
                AudienceType::MembersByRegime => $member->where('regime', $audience->regime),
                AudienceType::NewMembers => $member->where('adhesion_date', '>=', CarbonImmutable::today()->subMonths((int) $audience->new_member_months)->toDateString()),
                default => null,
            };
        });
    }
}
