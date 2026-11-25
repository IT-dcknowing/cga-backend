<?php

namespace App\Domain\Payments\Fne;

use App\Domain\Payments\Models\Invoice;
use App\Domain\Payments\Models\InvoicingProvider;
use App\Support\Http\Exceptions\ExternalServiceException;

/**
 * Service de certification FNE (02-TRD §10 bis.1, RG-FNE-02 à 04) : un pilote par mode d'une
 * organisation. Le service accepte la facture (n° FNE, QR de certification, cachet fiscal) ou la
 * rejette avec son motif ; indisponible, il lève ExternalServiceException et la facture reste en
 * attente pour un nouvel essai. En CI, seul le bouchon FakeFneCertifier répond.
 */
interface FneCertifier
{
    /**
     * @throws ExternalServiceException service indisponible ou réponse illisible
     */
    public function certify(Invoice $invoice, InvoicingProvider $provider): FneResult;

    /**
     * Essai de connexion avec les identifiants enregistrés (bouton « Tester »).
     */
    public function test(InvoicingProvider $provider): bool;
}
