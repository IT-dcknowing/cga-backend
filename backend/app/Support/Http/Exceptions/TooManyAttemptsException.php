<?php

namespace App\Support\Http\Exceptions;

/**
 * Trop d'essais (02-TRD §6.1 : connexion, demandes de code) : 429 too_many_attempts, avec le délai
 * d'attente en minutes dans le message, en secondes dans details.retry_after et l'en-tête Retry-After.
 */
class TooManyAttemptsException extends BusinessRuleException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct(
            'too_many_attempts',
            __('errors.too_many_attempts', ['minutes' => max(1, (int) ceil($retryAfterSeconds / 60))]),
            ['retry_after' => $retryAfterSeconds],
            429,
        );
    }
}
