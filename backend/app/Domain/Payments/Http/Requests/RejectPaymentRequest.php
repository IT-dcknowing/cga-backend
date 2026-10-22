<?php

namespace App\Domain\Payments\Http\Requests;

use App\Domain\Payments\Models\MemberPayment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/v1/cga/members/{member}/payments/{payment}/reject : rejet d'un paiement déclaré par
 * l'adhérent (F-PAY-03), motif obligatoire.
 */
class RejectPaymentRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function reason(): string
    {
        return (string) $this->validated('reason');
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
