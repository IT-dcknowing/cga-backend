<?php

namespace App\Domain\Notifications\Contracts;

use App\Support\Http\Exceptions\ExternalServiceException;

/**
 * Fournisseur d'envoi WhatsApp (02-TRD §10) : WhatsApp Business Cloud API, par modèles de messages
 * pré-approuvés ; pilote « log » tant qu'aucun compte n'est configuré.
 */
interface WhatsappProvider
{
    /**
     * @param  string  $to  numéro au format E.164
     * @param  list<string>  $parameters  valeurs des variables du modèle, dans l'ordre
     * @return string|null identifiant du message chez le fournisseur
     *
     * @throws ExternalServiceException fournisseur indisponible ou envoi refusé
     */
    public function send(string $to, string $template, string $language, array $parameters): ?string;
}
