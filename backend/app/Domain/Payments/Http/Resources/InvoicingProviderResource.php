<?php

namespace App\Domain\Payments\Http\Resources;

use App\Domain\Payments\Enums\CertificationMode;
use App\Domain\Payments\Fne\FneCertifiers;
use App\Domain\Payments\Models\InvoicingProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Écran « Facturation FNE » du centre (F-ADM-07) : mode en vigueur et modes proposés, adresse du
 * service, identifiants jamais renvoyés (seulement leurs 4 derniers caractères), expression de contrôle
 * du n° FNE, interrupteur, dernier test. Sans paramétrage enregistré : le mode manuel, par défaut.
 *
 * @property InvoicingProvider|null $resource
 */
class InvoicingProviderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $provider = $this->resource;
        $certifiers = app(FneCertifiers::class);
        $mode = $provider === null ? CertificationMode::Manual : $provider->mode;
        $credentials = $provider?->credentials;

        return [
            'mode' => $mode->value,
            'mode_label' => $mode->label(),
            'modes' => array_map(static fn (CertificationMode $option): array => [
                'value' => $option->value,
                'label' => $option->label(),
                'available' => $certifiers->available($option),
            ], CertificationMode::cases()),
            'base_url' => $provider?->base_url,
            'credentials_hint' => $credentials === null || $credentials === '' ? null : '••••'.mb_substr($credentials, -4),
            'fne_number_pattern' => $provider?->fne_number_pattern,
            'active' => $provider === null ? true : $provider->active,
            'last_test_at' => $provider?->last_test_at?->toIso8601ZuluString(),
            'last_test_ok' => $provider?->last_test_ok,
            'can' => [
                'update' => Gate::allows('manage', InvoicingProvider::class),
                'test' => Gate::allows('manage', InvoicingProvider::class) && $provider !== null && $certifiers->for($provider->mode) !== null,
            ],
        ];
    }
}
