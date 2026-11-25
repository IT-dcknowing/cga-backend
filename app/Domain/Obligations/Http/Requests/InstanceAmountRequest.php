<?php

namespace App\Domain\Obligations\Http\Requests;

use App\Domain\Obligations\Http\Requests\Concerns\ManagesMemberInstance;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/v1/cga/members/{member}/obligation-instances/{obligationInstance}/amount : « Saisir le montant
 * dû » (RG-OBL-08) ; pour la TEE, montant avant abattement en plus, au moins égal au montant dû.
 */
class InstanceAmountRequest extends FormRequest
{
    use ManagesMemberInstance;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'amount_due' => ['required', 'integer', 'min:0', 'max:'.config('cga.obligations.max_amount')],
            'amount_gross' => ['nullable', 'integer', 'gte:amount_due', 'max:'.config('cga.obligations.max_amount')],
        ];
    }

    public function amountDue(): int
    {
        return (int) $this->validated('amount_due');
    }

    public function amountGross(): ?int
    {
        $gross = $this->validated('amount_gross');

        return $gross === null ? null : (int) $gross;
    }
}
