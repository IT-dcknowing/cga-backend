<?php

/*
 * Sentry (06-IMPLEMENTATION-PLAN étape 0.3, 02-TRD §12.3) : erreurs envoyées seulement si
 * SENTRY_LARAVEL_DSN est renseigné, jamais de donnée personnelle (02-TRD §6.7, A09).
 */

it('reste désactivé sans DSN, ce qui évite tout appel réseau en test et en CI', function () {
    expect(config('sentry.dsn'))->toBeEmpty();
});

it('n\'envoie aucune donnée personnelle', function () {
    expect(config('sentry.send_default_pii'))->toBeFalse()
        ->and(config('sentry.breadcrumbs.sql_bindings'))->toBeFalse();
});

it('reçoit les erreurs signalées par Laravel', function () {
    expect(file_get_contents(base_path('bootstrap/app.php')))->toContain('Integration::handles($exceptions)');
});
