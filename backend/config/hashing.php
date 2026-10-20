<?php

/*
|--------------------------------------------------------------------------
| Hachage des mots de passe (02-TRD §6.1, A04)
|--------------------------------------------------------------------------
|
| Argon2id, toujours : l'algorithme n'est pas un choix d'environnement. Seul
| son coût est réglable (les tests le baissent dans phpunit.xml). Un mot de
| passe haché avec un coût plus ancien est re-haché à la connexion suivante.
|
*/

return [

    'driver' => 'argon2id',

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => env('HASH_VERIFY', true),
        'limit' => env('BCRYPT_LIMIT', null),
    ],

    'argon' => [
        'memory' => (int) env('ARGON_MEMORY', 65536),
        'threads' => (int) env('ARGON_THREADS', 1),
        'time' => (int) env('ARGON_TIME', 4),
        'verify' => env('HASH_VERIFY', true),
    ],

    'rehash_on_login' => true,

];
