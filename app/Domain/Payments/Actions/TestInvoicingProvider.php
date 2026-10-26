<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Fne\FneCertifiers;
use App\Domain\Payments\Models\InvoicingProvider;
use App\Support\Http\Exceptions\BusinessRuleException;

/**
 * Bouton « Tester » de l'écran « Facturation FNE » (F-ADM-07) : essai de connexion au service de
 * certification avec l'adresse et les identifiants enregistrés ; le résultat et sa date sont gardés.
 * Le mode manuel n'appelle aucun service.
 */
class TestInvoicingProvider
{
    public function __construct(private readonly FneCertifiers $certifiers) {}

    /**
     * @throws BusinessRuleException invoicing_test_unavailable
     */
    public function execute(string $organizationId): InvoicingProvider
    {
        $provider = InvoicingProvider::query()->where('organization_id', $organizationId)->first();
        $certifier = $provider === null ? null : $this->certifiers->for($provider->mode);
        if ($provider === null || $certifier === null) {
            throw new BusinessRuleException('invoicing_test_unavailable');
        }

        $provider->last_test_ok = $certifier->test($provider);
        $provider->last_test_at = now()->toImmutable();
        $provider->save();

        return $provider;
    }
}
