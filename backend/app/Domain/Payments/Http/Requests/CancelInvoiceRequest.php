<?php

namespace App\Domain\Payments\Http\Requests;

use App\Domain\Payments\Models\Invoice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/v1/cga/invoices/{invoice}/cancel : annulation d'une facture jamais certifiée (F-PAY-05),
 * motif obligatoire.
 */
class CancelInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', $this->invoice());
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
     * @throws AuthorizationException route sans facture
     */
    public function invoice(): Invoice
    {
        $invoice = $this->route('invoice');

        return $invoice instanceof Invoice ? $invoice : throw new AuthorizationException;
    }
}
