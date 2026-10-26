<?php

namespace App\Domain\Payments\Http\Requests;

use App\Domain\Members\Enums\FeesPeriodicity;
use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Payments\Models\MemberPayment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * PUT /api/v1/cga/fee-schedule : barème du CGA (F-PAY-01) — droit d'adhésion, honoraires et
 * périodicité pour le RME et le TEE.
 */
class FeeScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('updateFeeSchedule', MemberPayment::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = ['entry_fee' => ['required', 'integer', 'min:0', 'max:100000000'], 'fees' => ['required', 'array:RME,TEE']];
        foreach ([TaxRegime::Rme, TaxRegime::Tee] as $regime) {
            $rules["fees.{$regime->value}"] = ['required', 'array:amount,periodicity'];
            $rules["fees.{$regime->value}.amount"] = ['required', 'integer', 'min:0', 'max:100000000'];
            $rules["fees.{$regime->value}.periodicity"] = ['required', Rule::enum(FeesPeriodicity::class)];
        }

        return $rules;
    }

    public function entryFee(): int
    {
        return (int) $this->validated('entry_fee');
    }

    /**
     * @return array<string, array{amount: int, periodicity: FeesPeriodicity}>
     */
    public function fees(): array
    {
        $fees = [];
        foreach ((array) $this->validated('fees') as $regime => $fee) {
            $fee = (array) $fee;
            $fees[(string) $regime] = ['amount' => (int) $fee['amount'], 'periodicity' => FeesPeriodicity::from((string) $fee['periodicity'])];
        }

        return $fees;
    }
}
