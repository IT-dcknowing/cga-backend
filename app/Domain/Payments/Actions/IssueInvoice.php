<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Documents\Actions\IssueDocument;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Support\DocumentNumbering;
use App\Domain\Members\Models\Member;
use App\Domain\Payments\Enums\CertificationMode;
use App\Domain\Payments\Enums\ChargeStatus;
use App\Domain\Payments\Enums\InvoiceStatus;
use App\Domain\Payments\Enums\InvoiceType;
use App\Domain\Payments\Fne\FneCertifiers;
use App\Domain\Payments\Jobs\CertifyInvoice;
use App\Domain\Payments\Models\Invoice;
use App\Domain\Payments\Models\MemberCharge;
use App\Domain\Payments\Support\ChargeLabel;
use App\Domain\Payments\Support\InvoicingMode;
use App\Domain\Users\Models\Membership;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * « Émettre une facture » (F-PAY-05, 03-APPFLOW `/cga/factures`) : pour les sommes dues choisies d'un
 * dossier (droit d'adhésion, échéances d'honoraires, formation), non annulées et pas encore facturées,
 * la facture est créée avec son n° FAC (séquence du centre par année, sans trou), une ligne par somme
 * due (hors taxe, taux de TVA de la somme due) et ses totaux, calculés par le serveur ; elle est
 * aussitôt soumise à la certification FNE selon le mode du centre (RG-FNE-03) : envoyée au service
 * après validation de la transaction, ou en attente de la saisie de la certification en mode manuel.
 * Aucune facture n'est remise avant sa certification (RG-FNE-01).
 */
class IssueInvoice
{
    public function __construct(
        private readonly IssueDocument $documents,
        private readonly DocumentNumbering $numbering,
        private readonly InvoicingMode $modes,
        private readonly FneCertifiers $certifiers,
    ) {}

    /**
     * @param  list<string>  $chargeIds
     *
     * @throws ValidationException somme due inconnue, annulée ou déjà facturée
     * @throws BusinessRuleException cga_profile_incomplete, certification_mode_unavailable, abbreviation_missing
     */
    public function execute(Member $member, array $chargeIds, Membership $requester): Invoice
    {
        return DB::transaction(function () use ($member, $chargeIds, $requester): Invoice {
            $this->documents->ensureProfileComplete($member->organization_id);
            $mode = $this->modes->current($member->organization_id);
            if (! $this->certifiers->available($mode)) {
                throw new BusinessRuleException('certification_mode_unavailable', details: ['mode' => $mode->value]);
            }
            $charges = $this->charges($member, $chargeIds);

            $invoice = new Invoice;
            $invoice->organization_id = $member->organization_id;
            $invoice->type = InvoiceType::Invoice;
            $invoice->member_id = $member->id;
            $invoice->number = $this->numbering->next($member->organization_id, DocumentType::Invoice);
            $invoice->issue_date = today()->toImmutable();
            $invoice->lines = $charges->map(static fn (MemberCharge $charge): array => [
                'charge_id' => $charge->id,
                'label' => ChargeLabel::of($charge),
                'quantity' => 1,
                'unit_amount' => $charge->amount_excl_tax,
                'tax_rate' => $charge->tax_rate,
                'amount' => $charge->amount_excl_tax,
            ])->values()->all();
            $invoice->amount_excl_tax = (int) $charges->sum('amount_excl_tax');
            $invoice->tax_amount = (int) $charges->sum('tax_amount');
            $invoice->amount_incl_tax = $invoice->amount_excl_tax + $invoice->tax_amount;
            $invoice->status = InvoiceStatus::PendingCertification;
            $invoice->certification_mode = $mode;
            $invoice->created_by = $requester->user_id;
            $invoice->save();

            $charges->each(static function (MemberCharge $charge) use ($invoice): void {
                // Facturée : elle ne l'est plus qu'une fois, jusqu'à l'annulation ou l'avoir (InvoiceCharges).
                $charge->invoice_id = $invoice->id;
                $charge->save();
            });

            if ($mode !== CertificationMode::Manual) {
                CertifyInvoice::dispatch($invoice->id, $requester->id)->afterCommit();
            }

            return $invoice;
        });
    }

    /**
     * Sommes dues du dossier à facturer, dans l'ordre des échéances.
     *
     * @param  list<string>  $chargeIds
     * @return Collection<int, MemberCharge>
     *
     * @throws ValidationException
     */
    private function charges(Member $member, array $chargeIds): Collection
    {
        $charges = MemberCharge::query()
            ->where('member_id', $member->id)
            ->whereKey($chargeIds)
            ->where('status', '<>', ChargeStatus::Cancelled)
            ->whereNull('invoice_id')
            ->orderBy('due_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($chargeIds === [] || $charges->count() !== count(array_unique($chargeIds))) {
            throw ValidationException::withMessages(['charge_ids' => __('payments.charges_not_invoiceable')]);
        }

        return $charges;
    }
}
