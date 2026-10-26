<?php

use App\Domain\Documents\Actions\BlockAttestation;
use App\Domain\Documents\Http\Controllers\AttestationBlockController;
use App\Domain\Documents\Models\AttestationBlock;

/*
 * Blocage de l'attestation (01-PRD RG-DOC-10, RG-PAY-05, CR-04) : règles vérifiées sur le code.
 */

arch('RG-DOC-10 aucun blocage sans action du gérant : seule la route de blocage bloque une attestation')
    ->expect(BlockAttestation::class)
    ->toOnlyBeUsedIn([AttestationBlockController::class]);

arch('RG-PAY-05 aucune tâche planifiée, aucun Job, aucun écouteur ne bloque une attestation')
    ->expect(['App\Console', 'App\Domain\Documents\Jobs', 'App\Domain\Documents\Listeners', 'App\Domain\Imports\Jobs', 'App\Domain\Payments', 'App\Listeners'])
    ->not->toUse([BlockAttestation::class, AttestationBlock::class]);
