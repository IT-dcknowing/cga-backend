<?php

use App\Domain\Members\Actions\RequestRadiation;
use App\Domain\Members\Actions\ValidateRadiation;
use App\Domain\Members\Http\Controllers\RadiationController;

/*
 * Radiations (01-PRD F-ADH-06, D-04) : règles vérifiées sur le code.
 */

arch('RG-ADH-18 radiation toujours manuelle : seule la route de demande crée une radiation')
    ->expect(RequestRadiation::class)
    ->toOnlyBeUsedIn([RadiationController::class]);

arch('RG-ADH-18 radiation toujours manuelle : seules les routes de radiation la valident (validation immédiate comprise)')
    ->expect(ValidateRadiation::class)
    ->toOnlyBeUsedIn([RadiationController::class, RequestRadiation::class]);

arch('RG-ADH-18 aucune tâche planifiée, aucun Job, aucun écouteur ne crée ni ne valide une radiation')
    ->expect(['App\Console', 'App\Domain\Imports\Jobs', 'App\Domain\Members\Events', 'App\Listeners'])
    ->not->toUse([RequestRadiation::class, ValidateRadiation::class]);
