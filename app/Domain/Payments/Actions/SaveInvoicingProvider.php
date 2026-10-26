<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Enums\CertificationMode;
use App\Domain\Payments\Models\InvoicingProvider;
use App\Domain\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Paramétrage « Facturation FNE » du centre (F-ADM-07, RG-FNE-03) : mode de certification (manuel par
 * défaut), adresse et identifiants du service (chiffrés ; laissés vides, ceux déjà enregistrés sont
 * gardés), expression de contrôle du n° FNE saisi en mode manuel, interrupteur. Un mode API exige une
 * adresse et des identifiants ; changer d'adresse ou d'identifiants efface le résultat du dernier test.
 */
class SaveInvoicingProvider
{
    /**
     * @throws ValidationException identifiants manquants pour un mode API
     */
    public function execute(string $organizationId, CertificationMode $mode, ?string $baseUrl, ?string $credentials, ?string $pattern, bool $active, User $actor): InvoicingProvider
    {
        return DB::transaction(function () use ($organizationId, $mode, $baseUrl, $credentials, $pattern, $active, $actor): InvoicingProvider {
            $provider = InvoicingProvider::query()->where('organization_id', $organizationId)->lockForUpdate()->first() ?? new InvoicingProvider;

            if ($mode !== CertificationMode::Manual && $credentials === null && $provider->credentials === null) {
                throw ValidationException::withMessages(['credentials' => __('payments.fne_credentials_required')]);
            }

            $connectionChanged = $provider->base_url !== $baseUrl || $credentials !== null;
            $provider->organization_id = $organizationId;
            $provider->mode = $mode;
            $provider->base_url = $mode === CertificationMode::Manual ? null : $baseUrl;
            if ($credentials !== null) {
                $provider->credentials = $credentials;
            }
            $provider->fne_number_pattern = $pattern;
            $provider->active = $active;
            if ($connectionChanged) {
                $provider->last_test_at = null;
                $provider->last_test_ok = null;
            }
            $provider->updated_by = $actor->id;
            $provider->save();

            return $provider;
        });
    }
}
