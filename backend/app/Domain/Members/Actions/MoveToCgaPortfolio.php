<?php

namespace App\Domain\Members\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\MovementDirection;
use App\Domain\Members\Enums\PieceStatus;
use App\Domain\Members\Enums\PieceType;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\MemberPiece;
use App\Domain\Members\Models\PortfolioMovement;
use App\Domain\Members\Rules\RegimeOfPortfolio;
use App\Domain\Members\Support\MemberNumbering;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Payments\Actions\RecordPayment;
use App\Domain\Payments\Actions\SyncEntryFee;
use App\Domain\Payments\Data\PaymentEntry;
use App\Domain\Users\Models\User;
use App\Support\Files\StorageService;
use App\Support\Http\Exceptions\BusinessRuleException;
use App\Support\Numbering\SequenceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * « Basculer vers le portefeuille CGA » un dossier du Cabinet (RG-PTF-03, 05, 08) : régime RME ou TEE,
 * contrat CGA signé par les deux parties (date, confirmation ; l'exemplaire scanné reste une pièce
 * attendue s'il n'est pas joint), droit d'adhésion entièrement payé, avec le paiement saisi dans la
 * modale de bascule (03-APPFLOW §4), exclusivité sous verrou (RG-ADH-05). Le dossier reçoit un n° CGA Connect (année de la signature),
 * garde son n° client et son historique, peut être rattaché à un apporteur (RG-PTF-06) ; la bascule
 * est historisée et entre dans l'état « Nouveaux adhérents » du trimestre de la signature. C'est, avec
 * la validation de l'adhésion, la seule entrée au portefeuille CGA.
 */
class MoveToCgaPortfolio
{
    public function __construct(
        private readonly AssertMembershipExclusivity $exclusivity,
        private readonly SequenceService $sequences,
        private readonly MemberNumbering $numbering,
        private readonly CreateExpectedPieces $pieces,
        private readonly StorageService $storage,
        private readonly SyncEntryFee $entryFee,
        private readonly RecordPayment $payments,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  PaymentEntry|null  $entryFeePayment  paiement du droit d'adhésion saisi avec la bascule
     *
     * @throws BusinessRuleException portfolio_already_cga, member_not_active, membership_incomplete, membership_exclusive, abbreviation_missing
     * @throws ValidationException régime réel, paiement supérieur au droit dû
     */
    public function execute(Member $member, CarbonImmutable $contractSignedOn, ?UploadedFile $contract, ?string $referrerId, ?string $reason, User $actor, ?PaymentEntry $entryFeePayment = null): Member
    {
        return DB::transaction(function () use ($member, $contractSignedOn, $contract, $referrerId, $reason, $actor, $entryFeePayment): Member {
            $member = Member::query()->lockForUpdate()->findOrFail($member->id);
            if ($member->portfolio === Portfolio::Cga) {
                throw new BusinessRuleException('portfolio_already_cga');
            }
            if ($member->status !== MemberStatus::Active) {
                throw new BusinessRuleException('member_not_active');
            }
            if (! in_array($member->regime, RegimeOfPortfolio::cga(), true)) {
                throw ValidationException::withMessages(['regime' => __('members.move.regime_not_cga', ['regime' => $member->regime->value])]);
            }
            $this->exclusivity->execute($member->ncc, $member->organization_id);

            $organization = Organization::query()->lockForUpdate()->findOrFail($member->organization_id);
            if ($organization->numbering_abbreviation === null) {
                throw new BusinessRuleException('abbreviation_missing');
            }

            $file = $contract === null ? null : $this->storage->storeUpload($contract, 'contracts', $member->organization_id, $actor->id);
            $member->portfolio = Portfolio::Cga;
            $member->referrer_id = $referrerId;
            $member->cga_contract_signed_on = $contractSignedOn;
            $member->cga_contract_confirmed_by = $actor->id;
            $member->cga_contract_ended_on = null;
            $member->cga_contract_file_id = $file?->id;
            $member->adhesion_date = $contractSignedOn;
            $member->rsi_since = null;
            $member->membership_seq = $this->sequences->next($member->organization_id, MemberNumbering::MEMBERSHIP);
            $member->membership_number = $this->numbering->membershipNumber($member->membership_seq, $contractSignedOn->year, $organization->numbering_abbreviation);
            $member->updated_by = $actor->id;
            $member->save();

            $this->settleEntryFee($member, $entryFeePayment, $actor);
            $this->contractPiece($member);
            $this->movement($member, $contractSignedOn, $file?->id, $reason, $actor);
            $this->audit->record('portfolio_moved', $member, context: ['direction' => MovementDirection::CabinetToCga->value, 'number' => $member->membership_number]);

            return $member->refresh();
        });
    }

    /**
     * RG-PTF-03, RG-ADH-02 : à l'entrée au portefeuille CGA, le droit d'adhésion du dossier (montant
     * contractuel hors taxe, TVA en sus) est ouvert et doit être entièrement payé — par le paiement
     * saisi avec la bascule (confirmé d'office, reçu numéroté), ou déjà. Un droit à zéro n'ouvre
     * aucune somme due. Sinon, rien ne bascule.
     *
     * @throws BusinessRuleException membership_incomplete (entry_fee_payment)
     * @throws ValidationException paiement supérieur au droit restant dû
     */
    private function settleEntryFee(Member $member, ?PaymentEntry $payment, User $actor): void
    {
        $charge = $this->entryFee->execute($member);
        if ($charge === null || $charge->balance() === 0) {
            return;
        }

        if ($payment !== null) {
            $this->payments->execute($member, $payment, [$charge->id], null, $actor);
            $charge->refresh();
        }
        if ($charge->balance() > 0) {
            throw new BusinessRuleException('membership_incomplete', details: ['missing' => ['entry_fee_payment'], 'balance' => $charge->balance()]);
        }
    }

    /**
     * Le contrat CGA signé redevient une pièce attendue (reçue si l'exemplaire est joint, RG-PTF-08).
     */
    private function contractPiece(Member $member): void
    {
        $piece = MemberPiece::query()->where('member_id', $member->id)->where('piece_type', PieceType::SignedCgaContract)->first();
        if ($piece !== null) {
            $piece->status = $member->cga_contract_file_id === null ? PieceStatus::Expected : PieceStatus::Received;
            $piece->file_id = $member->cga_contract_file_id;
            $piece->received_at = $member->cga_contract_file_id === null ? null : today();
            $piece->save();
        }
        $this->pieces->execute($member);
    }

    private function movement(Member $member, CarbonImmutable $effectiveDate, ?string $fileId, ?string $reason, User $actor): void
    {
        $movement = new PortfolioMovement;
        $movement->organization_id = $member->organization_id;
        $movement->member_id = $member->id;
        $movement->direction = MovementDirection::CabinetToCga;
        $movement->effective_date = $effectiveDate;
        $movement->reason = $reason ?? __('members.move.default_reason');
        $movement->contract_signed_on = $effectiveDate;
        $movement->contract_file_id = $fileId;
        $movement->created_by = $actor->id;
        $movement->save();
    }
}
