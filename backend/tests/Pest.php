<?php

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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
