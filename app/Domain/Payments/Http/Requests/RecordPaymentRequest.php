<?php

namespace App\Domain\Payments\Http\Requests;

use App\Domain\Members\Http\Requests\Concerns\RouteMember;
use App\Domain\Payments\Data\PaymentEntry;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Models\MemberPayment;
use App\Support\Config\SettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/cga/members/{member}/payments : « Enregistrer un paiement » (F-PAY-02) — sommes dues
 * réglées (périodes cochées), montant, date, mode, référence, justificatif facultatif (multipart).
 */
class RecordPaymentRequest extends FormRequest
{
    use RouteMember;

    public function authorize(): bool
    {
        return Gate::allows('record', [MemberPayment::class, $this->member()]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(SettingsService $settings): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:100'],
            'charge_ids' => ['nullable', 'array'],
            'charge_ids.*' => ['required', 'string', 'distinct', 'max:26'],
            'proof' => ['nullable', 'file', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:'.((int) $settings->get('files.max_upload_mb') * 1024)],
        ];
    }

    public function entry(): PaymentEntry
    {
        return PaymentEntry::fromValidated($this->validated());
    }

    /**
     * @return list<string>
     */
    public function chargeIds(): array
    {
        return array_values(array_map('strval', (array) $this->validated('charge_ids', [])));
    }

    public function proof(): ?UploadedFile
    {
        $file = $this->validated('proof');

        return $file instanceof UploadedFile ? $file : null;
    }
}
