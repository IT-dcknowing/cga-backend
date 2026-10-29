<?php

namespace App\Domain\Obligations\Http\Requests;

use App\Domain\Obligations\Http\Requests\Concerns\ManagesMemberInstance;
use App\Support\Config\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * POST /api/v1/cga/members/{member}/obligation-instances/{obligationInstance}/payments : « Enregistrer un
 * paiement » d'une échéance (F-OBL-04) — montant, date, preuve facultative (multipart).
 */
class RecordObligationPaymentRequest extends FormRequest
{
    use ManagesMemberInstance;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(SettingsService $settings): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1', 'max:'.config('cga.obligations.max_amount')],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'proof' => ['nullable', 'file', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:'.((int) $settings->get('files.max_upload_mb') * 1024)],
        ];
    }

    public function amount(): int
    {
        return (int) $this->validated('amount');
    }

    public function paidOn(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->validated('paid_on'));
    }

    public function proof(): ?UploadedFile
    {
        $file = $this->validated('proof');

        return $file instanceof UploadedFile ? $file : null;
    }
}
