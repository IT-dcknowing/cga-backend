<?php

namespace App\Support\Logging;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;

/**
 * Ce que les journaux retiennent de la requête en cours : identifiant de corrélation (X-Request-Id,
 * posé par le middleware de l'étape 0.7b), adresse IP et navigateur. Rien hors d'une requête HTTP.
 */
class RequestContext
{
    public const REQUEST_ID_ATTRIBUTE = 'request_id';

    public function __construct(private readonly Container $container) {}

    public function requestId(): ?string
    {
        $id = $this->request()?->attributes->get(self::REQUEST_ID_ATTRIBUTE);

        return is_string($id) ? $id : null;
    }

    public function ip(): ?string
    {
        return $this->request()?->ip();
    }

    public function userAgent(): ?string
    {
        $userAgent = $this->request()?->userAgent();

        return $userAgent === null ? null : mb_substr($userAgent, 0, 255);
    }

    private function request(): ?Request
    {
        return $this->container->bound('request') ? $this->container->make('request') : null;
    }
}
