<?php

/*
 * Contrôle des seuils de couverture des Actions, des Policies et des fichiers modifiés (02-TRD §13).
 *
 * Usage : php tools/coverage-gate.php <clover.xml> --critical-min=90 --changed-min=85 [--changed=<liste.txt>]
 *   <liste.txt> : un chemin par ligne (fichiers modifiés par la demande de fusion).
 */

use Tools\Coverage\CoverageGate;

require __DIR__.'/../vendor/autoload.php';

$clover = null;
$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $argument, $match) === 1) {
        $options[$match[1]] = $match[2];
    } else {
        $clover = $argument;
    }
}

if ($clover === null || ! is_readable($clover) || ! isset($options['critical-min'], $options['changed-min'])) {
    fwrite(STDERR, "Usage : php tools/coverage-gate.php <clover.xml> --critical-min=90 --changed-min=85 [--changed=<liste.txt>]\n");
    exit(2);
}

$changed = [];
if (isset($options['changed']) && is_readable($options['changed'])) {
    $changed = array_values(array_filter(array_map('trim', file($options['changed']) ?: [])));
}

$gate = new CoverageGate((string) file_get_contents($clover), dirname(__DIR__));
$failures = $gate->failures((float) $options['critical-min'], (float) $options['changed-min'], $changed);

foreach ($failures as $failure) {
    fwrite(STDERR, "✗ {$failure}\n");
}

if ($failures === []) {
    echo "✓ Seuils de couverture des Actions, des Policies et des fichiers modifiés respectés.\n";
}

exit($failures === [] ? 0 : 1);
