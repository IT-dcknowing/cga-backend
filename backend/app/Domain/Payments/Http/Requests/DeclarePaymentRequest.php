<?php

namespace App\Domain\Payments\Http\Requests;

use App\Domain\Payments\Data\PaymentEntry;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Models\MemberPayment;
use App\Support\Config\SettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/member/payments : « Déclarer un paiement » par l'adhérent (F-PAY-03) — montant, date,
 * mode, référence et preuve obligatoire (photo ou PDF), en multipart.
 */
class DeclarePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('declare', MemberPayment::class);
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
            'proof' => ['required', 'file', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:'.((int) $settings->get('files.max_upload_mb') * 1024)],
        ];
    }

    public function entry(): PaymentEntry
    {
        return PaymentEntry::fromValidated($this->validated());
    }

    public function proof(): UploadedFile
    {
        /** @var UploadedFile $file preuve exigée par les règles */
        $file = $this->validated('proof');

        return $file;
    }
}
