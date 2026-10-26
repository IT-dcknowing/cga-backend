<?php

use Tools\Coverage\CoverageGate;

/*
 * Seuils de couverture des Actions, des Policies et des fichiers modifiés (02-TRD §13).
 */

function clover(array $files): string
{
    $xml = '<?xml version="1.0"?><coverage><project>';
    foreach ($files as $path => [$covered, $total]) {
        $xml .= sprintf('<file name="%s"><metrics statements="%d" coveredstatements="%d"/></file>', $path, $total, $covered);
    }

    return $xml.'</project></coverage>';
}

const BASE = 'C:\\depot\\backend';

it('accepte des Actions et Policies couvertes à 90 % et plus', function () {
    $gate = new CoverageGate(clover([
        BASE.'\\app\\Domain\\Members\\Actions\\ValidateMembership.php' => [18, 20],
        BASE.'\\app\\Domain\\Members\\Policies\\MemberPolicy.php' => [10, 10],
    ]), BASE);

    expect($gate->failures(90, 85, []))->toBe([]);
});

it('refuse des Actions et Policies couvertes à moins de 90 %, comptées ensemble', function () {
    $gate = new CoverageGate(clover([
        BASE.'\\app\\Domain\\Members\\Actions\\ValidateMembership.php' => [10, 20],
        BASE.'\\app\\Domain\\Members\\Policies\\MemberPolicy.php' => [10, 10],
        BASE.'\\app\\Support\\Health\\HealthMonitor.php' => [0, 50],
    ]), BASE);

    expect($gate->failures(90, 85, []))->toBe(['Actions et Policies : 66.7 % des lignes couvertes, minimum 90 %.']);
});

it('refuse un fichier modifié couvert à moins de 85 %', function () {
    $gate = new CoverageGate(clover([
        '/home/runner/work/depot/backend/app/Support/Health/HealthMonitor.php' => [8, 10],
        '/home/runner/work/depot/backend/app/Support/Config/SettingsService.php' => [9, 10],
    ]), '/home/runner/work/depot/backend');

    expect($gate->failures(90, 85, ['backend/app/Support/Health/HealthMonitor.php', 'app/Support/Config/SettingsService.php']))
        ->toBe(['app/Support/Health/HealthMonitor.php : 80.0 % des lignes couvertes, minimum 85 % pour un fichier modifié.']);
});

it('ignore les fichiers modifiés sans ligne exécutable ou hors du rapport', function () {
    $gate = new CoverageGate(clover([BASE.'\\app\\Support\\Health\\HealthCheck.php' => [0, 0]]), BASE);

    expect($gate->failures(90, 85, ['app/Support/Health/HealthCheck.php', 'README.md']))->toBe([]);
});

it('n\'exige rien des Actions et Policies tant qu\'il n\'y en a pas', function () {
    $gate = new CoverageGate(clover([BASE.'\\app\\Support\\Health\\HealthMonitor.php' => [1, 10]]), BASE);

    expect($gate->failures(90, 85, []))->toBe([]);
});

it('refuse un rapport illisible', function () {
    new CoverageGate('pas du XML', BASE);
})->throws(RuntimeException::class);
