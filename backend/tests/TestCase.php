<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Uri;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

abstract class TestCase extends BaseTestCase
{
    /**
     * Comme en production, chaque requête part de services de requête neufs (instances « scoped » :
     * espace actif, contexte du journal) : rien ne passe d'une requête à la suivante d'un même test.
     *
     * @param  string  $method
     * @param  Uri|string  $uri
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $cookies
     * @param  array<string, mixed>  $files
     * @param  array<string, mixed>  $server
     * @param  string|null  $content
     * @return TestResponse<Response>
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app?->forgetScopedInstances();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
