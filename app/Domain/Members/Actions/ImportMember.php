<?php

namespace App\Domain\Members\Actions;

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Models\Member;
use App\Domain\Payments\Actions\SyncEntryFee;
use App\Domain\Users\Models\User;
use App\Support\Http\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Dossier repris d'un portefeuille existant (F-IMP-02) : inscrit comme une saisie (doublon de NCC dans
 * le centre, exclusivité au portefeuille CGA, sous verrou pour un dossier actif), puis, s'il est complet
 * et « actif » dans le fichier, validé à sa date d'adhésion, contrat CGA confirmé par l'import, pièces
 * attendues créées. Il est réputé avoir acquitté son droit d'adhésion (RG-IMP-03). Son numéro CGA
 * Connect est attribué à la fin du lot, dans l'ordre chronologique des adhésions (RG-IMP-01) ; son
 * ancien numéro est gardé tel quel.
 */
class ImportMember
{
    public function __construct(
        private readonly CreateMemberDraft $create,
        private readonly CreateExpectedPieces $pieces,
        private readonly AssertMembershipExclusivity $exclusivity,
        private readonly SyncEntryFee $entryFee,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  champs de la fiche lus dans le fichier
     *
     * @throws BusinessRuleException ncc_already_registered, membership_exclusive
     */
    public function execute(string $organizationId, Portfolio $portfolio, array $attributes, ?CarbonImmutable $activeSince, User $actor): Member
    {
        return DB::transaction(function () use ($organizationId, $portfolio, $attributes, $activeSince, $actor): Member {
            if ($activeSince !== null && $portfolio === Portfolio::Cga) {
                $this->exclusivity->execute((string) $attributes['ncc'], $organizationId);
            }
            $member = $this->create->execute($organizationId, $portfolio, $attributes, null, $portfolio === Portfolio::Cga, $actor);
            if ($activeSince === null) {
                return $member;
            }

            $member->status = MemberStatus::Active;
            $member->adhesion_date = $activeSince;
            $member->validated_at = now()->toImmutable();
            $member->validated_by = $actor->id;
            $member->save();
            $this->pieces->execute($member);
            $this->entryFee->execute($member, settled: true);

            return $member;
        });
    }
}
