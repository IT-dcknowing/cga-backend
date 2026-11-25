<?php

namespace App\Support\Http\Exceptions;

use RuntimeException;

/**
 * Règle métier refusée (02-TRD §4.3, §4.4) : 409 { message, code, details } par défaut, rendu par
 * ApiErrorMapper. Le message vient de lang/fr/errors.php, sous le code de l'erreur.
 */
class BusinessRuleException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        ?string $message = null,
        public readonly array $details = [],
        public readonly int $status = 409,
    ) {
        parent::__construct($message ?? __("errors.{$errorCode}"));
    }
}
