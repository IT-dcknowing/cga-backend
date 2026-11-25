<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Members\Enums\RadiationStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\Radiation;
use App\Domain\Payments\Models\MemberPayment;
use App\Domain\Users\Models\Membership;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * « Documents ▾ » de la fiche (F-DOC-01) : production à la demande d'un document du dossier — contrat,
 * attestation de l'année, reçu d'un paiement confirmé du dossier, courrier de sa dernière radiation
 * validée. Le reçu et le courrier naissent d'ordinaire de leur événement ; la demande sert quand la
 * fiche du centre était alors incomplète.
 */
class ProduceMemberDocument
{
    /**
     * Documents produits à la demande depuis la fiche.
     */
    public const TYPES = [DocumentType::MembershipContract, DocumentType::MembershipCertificate, DocumentType::Receipt, DocumentType::RadiationNotice];

    public function __construct(
        private readonly ProduceContract $contract,
        private readonly ProduceCertificate $certificate,
        private readonly ProduceReceipt $receipt,
        private readonly ProduceRadiationNotice $notice,
    ) {}

    /**
     * @param  string|null  $paymentId  paiement du dossier dont produire le reçu
     *
     * @throws BusinessRuleException règles du document demandé, radiation_notice_unavailable sans radiation validée
     * @throws ValidationException paiement inconnu du dossier
     */
    public function execute(Member $member, DocumentType $type, ?string $paymentId, Membership $requester): GeneratedDocument
    {
        return match ($type) {
            DocumentType::MembershipContract => $this->contract->execute($member, $requester),
            DocumentType::MembershipCertificate => $this->certificate->execute($member, $requester),
            DocumentType::Receipt => $this->receipt->execute($this->payment($member, (string) $paymentId), $requester),
            DocumentType::RadiationNotice => $this->notice->execute($this->radiation($member), $requester),
            default => throw new LogicException("Document non produit à la demande : {$type->value}"),
        };
    }

    /**
     * @throws ValidationException
     */
    private function payment(Member $member, string $paymentId): MemberPayment
    {
        return MemberPayment::query()->where('member_id', $member->id)->whereKey($paymentId)->first()
            ?? throw ValidationException::withMessages(['payment_id' => __('documents.payment_not_found')]);
    }

    /**
     * @throws BusinessRuleException radiation_notice_unavailable
     */
    private function radiation(Member $member): Radiation
    {
        return Radiation::query()->where('member_id', $member->id)->where('status', RadiationStatus::Validated)->latest('validated_at')->first()
            ?? throw new BusinessRuleException('radiation_notice_unavailable');
    }
}
