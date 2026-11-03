<?php

namespace App\Domain\Dues\Http\Requests;

use App\Domain\Dues\Models\NetworkDue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/v1/network/dues/payments/{paymentId}/reject : rejet d'un paiement déclaré, avec son motif
 * (F-COT-03).
 */
class RejectDuesPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', NetworkDue::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:1000']];
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }
}
