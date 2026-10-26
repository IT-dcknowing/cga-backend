<?php

namespace App\Domain\Notifications\Contracts;

use App\Support\Http\Exceptions\ExternalServiceException;

/**
 * Fournisseur d'envoi de SMS (02-TRD §10) : pilote « log » au démarrage ; Orange CI et agrégateur
 * HTTP au lot 6, avec leurs identifiants saisis dans l'écran « Envois » (jamais dans .env).
 */
interface SmsProvider
{
    /**
     * @param  string  $to  numéro au format E.164 (+225…)
     *
     * @throws ExternalServiceException fournisseur indisponible ou envoi refusé
     */
    public function send(string $to, string $text): void;
}
