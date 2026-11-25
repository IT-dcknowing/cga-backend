<?php

namespace App\Domain\Auth\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * Lien d'activation d'une invitation (RG-ACC-03) : page /activer/{jeton} du SPA (app.frontend_url).
 */
class ActivationLink
{
    public function __construct(private readonly Repository $config) {}

    public function for(#[\SensitiveParameter] string $token): string
    {
        return rtrim($this->config->string('app.frontend_url'), '/').'/activer/'.$token;
    }
}
