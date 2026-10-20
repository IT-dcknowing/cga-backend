<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Tests Pest (02-TRD §13)
|--------------------------------------------------------------------------
|
| Les tests de tests/Feature démarrent l'application Laravel, sur PostgreSQL
| (base cga_plateforme_test). Ceux de tests/Unit et tests/Arch sont des tests
| PHP purs, sans application.
|
*/

pest()->extend(TestCase::class)->in('Feature');
