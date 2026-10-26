<?php

namespace App\Domain\Payments\Http\Requests;

use App\Domain\Payments\Models\MemberPayment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/v1/cga/members/{member}/payments/{payment}/confirm : confirmation d'un paiement déclaré par
 * l'adhérent (F-PAY-03), ventilé sur les sommes dues choisies ou, à défaut, les plus anciennes.
 */
class ConfirmPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('review', $this->payment());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'charge_ids' => ['nullable', 'array'],
            'charge_ids.*' => ['required', 'string', 'distinct', 'max:26'],
        ];
    }

    /**
     * @return list<string>
     */
    public function chargeIds(): array
    {
        return array_values(array_map('strval', (array) $this->validated('charge_ids', [])));
    }

    /**
     * @throws AuthorizationException route sans paiement
     */
    public function payment(): MemberPayment
    {
        $payment = $this->route('payment');

        return $payment instanceof MemberPayment ? $payment : throw new AuthorizationException;
    }
}
