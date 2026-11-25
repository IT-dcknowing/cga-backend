<?php

namespace Tests\Support;

/**
 * Requêtes du SPA (02-TRD §6.1) : Sanctum n'ouvre la session que pour une origine déclarée dans
 * SANCTUM_STATEFUL_DOMAINS (phpunit.xml : localhost:5173).
 */
final class Spa
{
    public const ORIGIN = 'http://localhost:5173';

    /**
     * @return array<string, string>
     */
    public static function headers(): array
    {
        return ['Origin' => self::ORIGIN, 'Referer' => self::ORIGIN.'/'];
    }
}
