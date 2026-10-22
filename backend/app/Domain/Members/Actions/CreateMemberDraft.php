<?php

namespace App\Domain\Members\Actions;

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\MemberTransfer;
use App\Domain\Members\Support\MemberFields;
use App\Domain\Members\Support\MembershipExclusivity;
use App\Domain\Payments\Actions\SyncEntryFee;
use App\Domain\Users\Models\User;
use App\Support\Http\Exceptions\BusinessRuleException;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Inscription d'un adhérent, enregistrée comme brouillon (F-ADH-02, RG-ADH-01) : sans numéro, dans
 * le portefeuille choisi (RG-PTF-02). Un NCC n'a qu'un dossier ouvert dans le centre (RG-ADH-05) ;
 * inscrit au portefeuille CGA d'un autre CGA, il ne s'inscrit pas au portefeuille CGA (409 sans nom de
 * CGA). Les montants non saisis viennent du barème du CGA ; le droit d'adhésion du portefeuille CGA est
 * ouvert comme somme due (F-PAY-02). Un dossier pré-rempli par un réimport depuis
 * un autre CGA (RG-ADH-15) y est relié.
 */
class CreateMemberDraft
{
    public function __construct(
        private readonly MemberFields $fields,
        private readonly MembershipExclusivity $exclusivity,
        private readonly SaveReferenceRevenue $revenue,
        private readonly SyncEntryFee $entryFee,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  saisie validée de la fiche
     * @param  array{year: int, amount: int, kind: string}|null  $revenue  chiffre d'affaires de référence
     * @param  string|null  $transferId  réimport depuis un autre CGA dont l'inscription reprend l'identité
     *
     * @throws BusinessRuleException ncc_already_registered, membership_exclusive
     * @throws ValidationException réimport inconnu, déjà utilisé ou d'un autre NCC
     */
    public function execute(string $organizationId, Portfolio $portfolio, array $attributes, ?array $revenue, bool $contractConfirmed, User $actor, ?string $transferId = null): Member
    {
        $attributes = $this->fields->prepare($attributes);
        $ncc = (string) $attributes['ncc'];

        return DB::transaction(function () use ($organizationId, $portfolio, $attributes, $revenue, $contractConfirmed, $actor, $ncc, $transferId): Member {
            AssertNccAvailable::here($ncc);
            $transfer = $transferId === null ? null : $this->transfer($transferId, $organizationId, $ncc);
            if ($portfolio === Portfolio::Cga && $this->exclusivity->enrolledElsewhere($ncc, $organizationId)) {
                throw new BusinessRuleException('membership_exclusive');
            }

            $member = new Member($this->fields->withSchedule($attributes, TaxRegime::from((string) $attributes['regime'])));
            $member->portfolio = $portfolio;
            $member->status = MemberStatus::Draft;
            $member->regime_since ??= today();
            $member->referrer_id = $portfolio === Portfolio::Cga ? ($attributes['referrer_id'] ?? null) : null;
            $member->cga_contract_signed_on = $portfolio === Portfolio::Cga ? ($attributes['cga_contract_signed_on'] ?? null) : null;
            $member->cga_contract_confirmed_by = $portfolio === Portfolio::Cga && $contractConfirmed ? $actor->id : null;
            $member->created_by = $actor->id;
            $member->updated_by = $actor->id;
            $member->save();

            if ($revenue !== null) {
                $this->revenue->execute($member, $revenue);
            }
            if ($transfer !== null) {
                $transfer->to_member_id = $member->id;
                $transfer->save();
            }
            $this->entryFee->execute($member);

            return $member->refresh();
        });
    }

    /**
     * Réimport du centre, pas encore utilisé, du même NCC.
     *
     * @throws ValidationException
     */
    private function transfer(string $transferId, string $organizationId, string $ncc): MemberTransfer
    {
        $transfer = MemberTransfer::query()->whereKey($transferId)->where('to_organization_id', $organizationId)->whereNull('to_member_id')->lockForUpdate()->first();
        if ($transfer === null) {
            throw ValidationException::withMessages(['transfer_id' => __('members.transfer.invalid')]);
        }
        $sourceNcc = Tenancy::bypass(static fn (): mixed => Member::query()->whereKey($transfer->from_member_id)->value('ncc'), 'Réimport depuis un autre CGA (RG-ADH-15) : NCC du dossier d\'origine.');
        if ($sourceNcc !== $ncc) {
            throw ValidationException::withMessages(['transfer_id' => __('members.transfer.ncc_mismatch')]);
        }

        return $transfer;
    }
}
