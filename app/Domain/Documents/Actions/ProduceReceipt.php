<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Data\DocumentRequest;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\MemberPayment;
use App\Domain\Users\Models\Membership;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Reçu d'un paiement confirmé (F-PAY-02, RG-PAY-02) : numéroté du n° de reçu attribué à la
 * confirmation, visible de l'adhérent ; produit à la confirmation (ProduceReceiptOnConfirmation) ou, si
 * la fiche du centre était alors incomplète, à la demande. Un paiement n'a qu'un reçu en cours.
 */
class ProduceReceipt
{
    public function __construct(private readonly IssueDocument $issue) {}

    /**
     * @throws BusinessRuleException receipt_unavailable, receipt_already_issued, cga_profile_incomplete, template_missing
     */
    public function execute(MemberPayment $payment, Membership $requester): GeneratedDocument
    {
        return DB::transaction(function () use ($payment, $requester): GeneratedDocument {
            $this->issue->ensureProfileComplete($requester->organization_id);
            $payment = MemberPayment::query()->with('member')->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== PaymentStatus::Confirmed || $payment->receipt_number === null) {
                throw new BusinessRuleException('receipt_unavailable');
            }

            $issued = GeneratedDocument::query()
                ->where('type', DocumentType::Receipt)
                ->where('source_type', 'payment')
                ->where('source_id', $payment->id)
                ->where('status', '<>', DocumentStatus::Cancelled)
                ->exists();
            if ($issued) {
                throw new BusinessRuleException('receipt_already_issued');
            }

            $document = $this->issue->execute(new DocumentRequest(
                type: DocumentType::Receipt,
                title: (string) __('documents.title', ['type' => DocumentType::Receipt->label(), 'member' => $payment->member->company_name]),
                memberId: $payment->member_id,
                number: $payment->receipt_number,
                sourceType: 'payment',
                sourceId: $payment->id,
            ), $requester);

            $payment->receipt_document_id = $document->id;
            $payment->save();

            return $document;
        });
    }
}
