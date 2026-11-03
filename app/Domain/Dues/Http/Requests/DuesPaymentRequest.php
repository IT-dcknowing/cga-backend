<?php

namespace App\Domain\Dues\Http\Requests;

use App\Domain\Dues\Models\NetworkDue;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Support\Config\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Paiement de cotisation : déclaré par le CGA (POST /api/v1/cga/dues/{networkDue}/payments, preuve
 * obligatoire, F-COT-03, multipart) ou saisi par le CNCGA (POST /api/v1/network/dues/{dueId}/payments,
 * F-COT-04) — montant, date (passée ou du jour), mode, référence.
 */
class DuesPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows($this->declaration() ? 'declare' : 'manage', NetworkDue::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(SettingsService $settings): array
    {
        $rules = [
            'amount' => ['required', 'integer', 'min:1', 'max:'.(int) config('cga.obligations.max_amount')],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
        if ($this->declaration()) {
            $rules['proof'] = ['required', 'file', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:'.((int) $settings->get('files.max_upload_mb') * 1024)];
        }

        return $rules;
    }

    /** Déclaration du CGA (route avec la cotisation liée), plutôt que saisie du CNCGA. */
    public function declaration(): bool
    {
        return $this->route('networkDue') instanceof NetworkDue;
    }

    public function amount(): int
    {
        return (int) $this->validated('amount');
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

    public function proof(): UploadedFile
    {
        /** @var UploadedFile */
        return $this->validated('proof');
    }
}
