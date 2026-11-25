<?php

/*
 * Disque du serveur, hors de la racine web (02-TRD §6.4, T-11) : documents, pièces, exports.
 * Jamais servi directement ; tout accès passe par un contrôleur qui vérifie les droits.
 */
$privateDisk = [
    'driver' => 'local',
    'root' => env('FILES_ROOT', storage_path('app/private')),
    'visibility' => 'private',
    'serve' => false,
    'throw' => true,
    'report' => false,
];

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'private'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'private' => $privateDisk,

        // Laravel ajoute ses propres disques « local » (servi sur /storage) et « public » à ceux
        // déclarés ici : ils sont redéfinis sur le disque privé pour ne jamais exposer de fichier.
        'local' => $privateDisk,
        'public' => $privateDisk,

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    // Aucun lien public/storage : aucun fichier n'est servi par le web (02-TRD §6.4).
    'links' => [],

];
