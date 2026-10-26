<?php

namespace App\Domain\Payments\Http\Requests;

use App\Domain\Payments\Enums\CertificationMode;
use App\Domain\Payments\Fne\FneCertifiers;
use App\Domain\Payments\Models\InvoicingProvider;
use App\Domain\Payments\Rules\AllowedFneHost;
use App\Domain\Payments\Rules\ValidPattern;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * PUT /api/v1/cga/invoicing-provider : « Facturation FNE » du centre (F-ADM-07) — mode de certification
 * pris en charge par la plateforme, adresse du service (HTTPS, hôte autorisé) et identifiants pour un
 * mode API (identifiants vides : ceux enregistrés sont gardés), expression de contrôle du n° FNE,
 * interrupteur ; gérant (integrations.manage).
 */
class SaveInvoicingProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', InvoicingProvider::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(FneCertifiers $certifiers, AllowedFneHost $host): array
    {
        $modes = array_values(array_filter(CertificationMode::cases(), static fn (CertificationMode $mode): bool => $certifiers->available($mode)));

        return [
            'mode' => ['required', 'string', Rule::in(array_map(static fn (CertificationMode $mode): string => $mode->value, $modes))],
            'base_url' => ['nullable', 'required_unless:mode,manual', 'string', 'max:255', 'url', $host],
            'credentials' => ['nullable', 'string', 'max:2000'],
            'fne_number_pattern' => ['nullable', 'string', 'max:120', new ValidPattern],
            'active' => ['required', 'boolean'],
        ];
    }

    public function mode(): CertificationMode
    {
        return CertificationMode::from((string) $this->validated('mode'));
    }

    public function baseUrl(): ?string
    {
        $url = $this->validated('base_url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    public function credentials(): ?string
    {
        $credentials = $this->validated('credentials');

        return is_string($credentials) && $credentials !== '' ? $credentials : null;
    }

    public function pattern(): ?string
    {
        $pattern = $this->validated('fne_number_pattern');

        return is_string($pattern) && $pattern !== '' ? $pattern : null;
    }

    public function active(): bool
    {
        return (bool) $this->validated('active');
    }
}
