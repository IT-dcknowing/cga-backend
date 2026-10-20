<?php

namespace App\Support\Http\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Règle métier refusée (02-TRD §4.3, §4.4) : 409 { message, code, details } par défaut. Le message
 * vient de lang/fr/errors.php, sous le code de l'erreur.
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

    public function render(): JsonResponse
    {
        return new JsonResponse([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'details' => (object) $this->details,
        ], $this->status);
    }
}
