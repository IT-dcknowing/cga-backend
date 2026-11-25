<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Support\DocumentNumbering;
use App\Domain\Payments\Enums\ChargeStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Events\PaymentConfirmed;
use App\Domain\Payments\Models\MemberCharge;
use App\Domain\Payments\Models\MemberPayment;
use App\Domain\Payments\Models\PaymentAllocation;
use App\Domain\Users\Models\User;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Confirmation d'un paiement (F-PAY-02, 03) : il est ventilé sur les sommes dues choisies, ou à défaut
 * sur les plus anciennes encore ouvertes, sans dépasser le reste dû ; chaque somme passe « payée en
 * partie » ou « payée » ; le reçu reçoit son numéro, séquence du centre par année sans trou
 * (REC-ABR-AAAA-NNNNN, RG-PAY-02). Le reçu PDF est produit par la chaîne documentaire, à l'écoute de
 * PaymentConfirmed.
 */
class ConfirmPayment
{
    public function __construct(private readonly DocumentNumbering $numbering) {}

    /**
     * @param  list<string>  $chargeIds  sommes dues à régler ; vide : les plus anciennes encore ouvertes
     *
     * @throws BusinessRuleException payment_not_pending, abbreviation_missing
     * @throws ValidationException somme due inconnue, montant supérieur au reste dû
     */
    public function execute(MemberPayment $payment, array $chargeIds, User $actor): MemberPayment
    {
        return DB::transaction(function () use ($payment, $chargeIds, $actor): MemberPayment {
            $payment = MemberPayment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== PaymentStatus::PendingConfirmation) {
                throw new BusinessRuleException('payment_not_pending');
            }

            $charges = $this->charges($payment, $chargeIds);
            $balance = (int) $charges->sum(static fn (MemberCharge $charge): int => $charge->balance());
            if ($payment->amount > $balance) {
                throw ValidationException::withMessages(['amount' => __('payments.amount_exceeds_balance', ['balance' => number_format($balance, 0, ',', ' ')])]);
            }

            $this->allocate($payment, $charges);
            $payment->status = PaymentStatus::Confirmed;
            $payment->confirmed_by = $actor->id;
            $payment->confirmed_at = now()->toImmutable();
            $payment->receipt_number = $this->numbering->next($payment->organization_id, DocumentType::Receipt);
            $payment->save();

            PaymentConfirmed::dispatch($payment);

            return $payment->refresh();
        });
    }

    /**
     * Sommes dues ouvertes du dossier, dans l'ordre des échéances.
     *
     * @param  list<string>  $chargeIds
     * @return Collection<int, MemberCharge>
     *
     * @throws ValidationException
     */
    private function charges(MemberPayment $payment, array $chargeIds): Collection
    {
        $charges = MemberCharge::query()
            ->where('member_id', $payment->member_id)
            ->whereIn('status', [ChargeStatus::Due, ChargeStatus::Partial, ChargeStatus::Overdue])
            ->when($chargeIds !== [], static fn ($query) => $query->whereKey($chargeIds))
            ->orderBy('due_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($chargeIds !== [] && $charges->count() !== count(array_unique($chargeIds))) {
            throw ValidationException::withMessages(['charge_ids' => __('payments.charge_not_open')]);
        }

        return $charges;
    }

    /**
     * @param  Collection<int, MemberCharge>  $charges
     */
    private function allocate(MemberPayment $payment, Collection $charges): void
    {
        $left = $payment->amount;
        foreach ($charges as $charge) {
            if ($left === 0) {
                break;
            }
            $part = min($left, $charge->balance());
            $left -= $part;

            $allocation = new PaymentAllocation;
            $allocation->payment_id = $payment->id;
            $allocation->charge_id = $charge->id;
            $allocation->amount = $part;
            $allocation->save();

            $charge->amount_paid += $part;
            $charge->status = $charge->balance() === 0 ? ChargeStatus::Paid : ChargeStatus::Partial;
            $charge->overdue_since = $charge->balance() === 0 ? null : $charge->overdue_since;
            $charge->save();
        }
    }
}
