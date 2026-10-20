<?php

use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\Spa;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Tests Pest (02-TRD §13)
|--------------------------------------------------------------------------
|
| Les tests de tests/Feature démarrent l'application Laravel, sur PostgreSQL
| (base cga_plateforme_test), migrée au premier accès ; les files, le cache et
| les sessions y vivent aussi (02-TRD §13). Ceux de tests/Unit, tests/Arch et
| tests/Tooling sont des tests PHP purs, sans application.
|
*/

pest()->extend(TestCase::class)->use(LazilyRefreshDatabase::class)->in('Feature');

/**
 * Requête du SPA connecté dans l'espace de ce rattachement (02-TRD §6.2).
 */
function inSpace(Membership $membership): TestCase
{
    return test()->actingAs($membership->user)
        ->withHeaders(Spa::headers())
        ->withSession([TenantContext::SESSION_KEY => $membership->id]);
}
