<?php

namespace App\Domain\Auth\Data;

/**
 * Code à usage unique qui vient d'être envoyé. Le code en clair ne va que vers la notification et,
 * en développement seulement, vers la réponse (DevMessages).
 */
final readonly class IssuedCode
{
    public function __construct(
        public string $channel,
        public string $target,
        #[\SensitiveParameter] public string $code,
        public int $validityMinutes,
    ) {}
}
