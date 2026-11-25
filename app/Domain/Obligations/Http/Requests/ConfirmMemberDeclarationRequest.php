<?php

namespace App\Domain\Obligations\Http\Requests;

use App\Domain\Obligations\Data\DeclarationReview;
use App\Domain\Obligations\Http\Requests\Concerns\ManagesMemberInstance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/cga/members/{member}/obligation-instances/{obligationInstance}/member-declaration/confirm :
 * « Confirmer la déclaration de l'adhérent » ou la « Corriger » (RG-OBL-09) — valeurs retenues par le
 * CGA, toutes facultatives : date de la déclaration et montant à payer ; montant et date du paiement,
 * montant dû s'il manquait.
 */
class ConfirmMemberDeclarationRequest extends FormRequest
{
    use ManagesMemberInstance;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $max = 'max:'.config('cga.obligations.max_amount');

        return [
            'done_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'amount' => ['nullable', 'integer', 'min:1', $max],
            'paid_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'amount_due' => ['nullable', 'integer', 'min:0', 'required_with:amount_gross', $max],
            'amount_gross' => ['nullable', 'integer', 'gte:amount_due', $max],
        ];
    }

    public function review(): DeclarationReview
    {
        return new DeclarationReview(
            doneOn: $this->validatedDate('done_on'),
            amount: $this->validatedInteger('amount'),
            paidOn: $this->validatedDate('paid_on'),
            amountDue: $this->validatedInteger('amount_due'),
            amountGross: $this->validatedInteger('amount_gross'),
        );
    }

    private function validatedDate(string $key): ?CarbonImmutable
    {
        $value = $this->validated($key);

        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }

    private function validatedInteger(string $key): ?int
    {
        $value = $this->validated($key);

        return $value === null ? null : (int) $value;
    }
}
