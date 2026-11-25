<?php

use App\Domain\Organizations\Actions\SuspendCga;
use App\Domain\Organizations\Actions\WithdrawAgreement;
use App\Domain\Organizations\Http\Controllers\OrganizationStatusController;

/*
 * Organisations (01-PRD M02) : règles vérifiées sur le code.
 */

arch('RG-ORG-09 aucune suspension automatique : seules les actions de la Présidente suspendent un CGA')
    ->expect(SuspendCga::class)
    ->toOnlyBeUsedIn([OrganizationStatusController::class, WithdrawAgreement::class]);
