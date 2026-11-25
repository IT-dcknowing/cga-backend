<?php

namespace App\Domain\Trainings\Http\Requests;

use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Trainings\Http\Requests\Concerns\OrganizerTraining;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /{network|cga}/trainings/{training}/registrations/{registrationId}/payment : paiement d'une formation
 * payante encaissé hors plateforme (RG-FOR-06) — date (passée ou du jour), mode, référence. Le montant est le
 * prix de la formation.
 */
class TrainingPaymentRequest extends FormRequest
{
    use OrganizerTraining;

    public function authorize(): bool
    {
        return $this->managesTraining();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function paidOn(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->validated('paid_on'));
    }

    public function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::from((string) $this->validated('method'));
    }

    public function reference(): ?string
    {
        $reference = $this->validated('reference');

        return is_string($reference) && trim($reference) !== '' ? trim($reference) : null;
    }
}
