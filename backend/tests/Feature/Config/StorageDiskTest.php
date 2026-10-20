<?php

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Stockage sur le disque « private » (06-IMPLEMENTATION-PLAN étape 0.3, 02-TRD §6.4, T-11).
 */

it('range les fichiers sur le disque private par défaut', function () {
    expect(config('filesystems.default'))->toBe('private')
        ->and(config('filesystems.disks.private.driver'))->toBe('local');
});

it('garde le disque private hors de la racine web et ne le sert jamais directement', function () {
    $root = realpath(config('filesystems.disks.private.root'));

    expect($root)->not->toBeFalse()
        ->and(Str::startsWith($root, realpath(public_path())))->toBeFalse()
        ->and(config('filesystems.disks.private.serve'))->toBeFalse()
        ->and(config('filesystems.disks.private.visibility'))->toBe('private');
});

it('neutralise les disques local et public ajoutés par Laravel, et n\'a aucun lien public/storage', function (string $disk) {
    expect(config("filesystems.disks.{$disk}"))->toBe(config('filesystems.disks.private'))
        ->and(config('filesystems.links'))->toBe([]);
})->with(['local', 'public']);

it('signale toute erreur d\'écriture au lieu de l\'ignorer', function () {
    expect(config('filesystems.disks.private.throw'))->toBeTrue()
        ->and(config('filesystems.disks.s3.throw'))->toBeTrue();
});

it('écrit et relit un fichier par l\'abstraction de Laravel', function () {
    Storage::fake();

    Storage::put('orgs/01J0000000000000000000000/documents/2026/essai.txt', 'contenu');

    expect(Storage::get('orgs/01J0000000000000000000000/documents/2026/essai.txt'))->toBe('contenu');
});
