<?php

namespace App\Domain\Obligations\Http\Requests;

use App\Domain\Obligations\Http\Requests\Concerns\ManagesMemberInstance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/cga/members/{member}/obligation-instances/{obligationInstance}/done : « Marquer faite »
 * une déclaration (RG-OBL-05) — date, et pour une obligation « déclaration puis paiement », montant à
 * payer (avant abattement en plus pour la TEE). Confirme aussi la déclaration faite signalée par
 * l'adhérent (RG-OBL-09).
 */
class MarkDeclarationDoneRequest extends FormRequest
{
    use ManagesMemberInstance;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'done_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'amount_due' => ['nullable', 'integer', 'min:0', 'required_with:amount_gross', 'max:'.config('cga.obligations.max_amount')],
            'amount_gross' => ['nullable', 'integer', 'gte:amount_due', 'max:'.config('cga.obligations.max_amount')],
        ];
    }

    public function doneOn(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->validated('done_on'));
    }

    public function amountDue(): ?int
    {
        $due = $this->validated('amount_due');

        return $due === null ? null : (int) $due;
    }

    public function amountGross(): ?int
    {
        $gross = $this->validated('amount_gross');

        return $gross === null ? null : (int) $gross;
    }
}
