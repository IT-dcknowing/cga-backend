<?php

namespace App\Domain\Members\Http\Requests;

use App\Domain\Members\Http\Requests\Concerns\RouteMember;
use App\Domain\Payments\Data\PaymentEntry;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/cga/members/{member}/portfolio/cga : « Basculer vers le portefeuille CGA » (RG-PTF-03) —
 * date de signature du contrat CGA, confirmation « signé par les deux parties », exemplaire signé
 * (facultatif, en multipart), apporteur et motif facultatifs, paiement du droit d'adhésion (montant,
 * date, mode, référence), exigé tant que le droit n'est pas entièrement payé.
 */
class MoveToCgaRequest extends FormRequest
{
    use RouteMember;

    public function authorize(): bool
    {
        return Gate::allows('moveToCga', $this->member());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(SettingsService $settings, TenantContext $context): array
    {
        return [
            'cga_contract_signed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'cga_contract_confirmed' => ['accepted'],
            'contract' => ['nullable', 'file', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:'.((int) $settings->get('files.max_upload_mb') * 1024)],
            'referrer_id' => ['nullable', 'string', Rule::exists('business_referrers', 'id')->where('organization_id', $context->organizationId())->where('active', true)],
            'reason' => ['nullable', 'string', 'max:1000'],
            'entry_fee_payment' => ['nullable', 'array:amount,paid_on,method,reference'],
            'entry_fee_payment.amount' => ['required_with:entry_fee_payment', 'integer', 'min:1'],
            'entry_fee_payment.paid_on' => ['required_with:entry_fee_payment', 'date_format:Y-m-d', 'before_or_equal:today'],
            'entry_fee_payment.method' => ['required_with:entry_fee_payment', Rule::enum(PaymentMethod::class)],
            'entry_fee_payment.reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Paiement du droit d'adhésion saisi dans la modale de bascule (03-APPFLOW §4).
     */
    public function entryFeePayment(): ?PaymentEntry
    {
        $payment = $this->validated('entry_fee_payment');

        return is_array($payment) && $payment !== [] ? PaymentEntry::fromValidated($payment) : null;
    }

    public function contractSignedOn(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->validated('cga_contract_signed_on'));
    }

    public function contract(): ?UploadedFile
    {
        $file = $this->validated('contract');

        return $file instanceof UploadedFile ? $file : null;
    }

    public function referrerId(): ?string
    {
        $referrer = $this->validated('referrer_id');

        return is_string($referrer) ? $referrer : null;
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) ? $reason : null;
    }
}
