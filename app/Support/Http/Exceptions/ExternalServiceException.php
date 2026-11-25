<?php

namespace App\Support\Http\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Fournisseur externe indisponible ou en erreur (SMS, WhatsApp, SMTP, LibreOffice, FNE, Compta Flow) :
 * 502 « Service externe indisponible, réessayez » ; la réponse du fournisseur va au journal d'erreurs,
 * jamais au client (02-TRD §4.4).
 */
class ExternalServiceException extends RuntimeException
{
    public function __construct(
        public readonly string $service,
        public readonly ?string $providerResponse = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct("Service externe indisponible : {$service}.", 0, $previous);
    }

    /**
     * Contexte ajouté au journal d'erreurs par Laravel quand l'exception est signalée.
     *
     * @return array<string, string|null>
     */
    public function context(): array
    {
        return ['service' => $this->service, 'provider_response' => $this->providerResponse];
    }
}
