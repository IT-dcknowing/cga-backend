<?php

namespace Tests\Support;

use App\Support\Config\SettingsService;

/**
 * Conversions réelles par LibreOffice dans les tests : un profil stable par processus de test (gardé
 * d'une exécution à l'autre, sa création coûte une vingtaine de secondes) et un délai large, la suite
 * parallèle lançant plusieurs conversions à la fois sur des postes parfois lents.
 */
final class LibreOffice
{
    public const TIMEOUT_SECONDS = 240;

    public static function prepare(): void
    {
        $token = getenv('TEST_TOKEN');

        config(['pdf.libreoffice.profile_slot' => 'tests-'.(is_string($token) && $token !== '' ? $token : '0')]);
        app(SettingsService::class)->set('pdf.timeout_seconds', self::TIMEOUT_SECONDS);
    }
}
