<?php

/*
 * Variabilisation (02-TRD §7.6, §13) : toute clé de paramètre lue ou écrite par le code est déclarée
 * dans le registre config/cga.php.
 */

it('ne lit ni n\'écrit aucun paramètre absent du registre', function () {
    $registry = require __DIR__.'/../../config/cga.php';
    $declared = function (string $key) use ($registry): bool {
        $node = $registry['settings'];
        foreach (explode('.', $key) as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return false;
            }
            $node = $node[$segment];
        }

        return is_array($node) && array_key_exists('default', $node) && array_key_exists('level', $node);
    };

    $used = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../app'));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        preg_match_all("/settings(?:\(\))?->(?:get|default|set|reset)\(\s*'([a-z0-9_.]+)'/", (string) file_get_contents($file->getPathname()), $matches);
        foreach ($matches[1] as $key) {
            $used[$key] = $file->getFilename();
        }
    }

    expect($used)->not->toBeEmpty();
    foreach ($used as $key => $fileName) {
        expect($declared($key))->toBeTrue("Clé {$key} lue dans {$fileName} mais absente de config/cga.php");
    }
});
