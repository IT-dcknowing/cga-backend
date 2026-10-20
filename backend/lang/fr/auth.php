<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Messages d'authentification
    |--------------------------------------------------------------------------
    |
    | Un échec de connexion ne révèle jamais si le compte existe (02-TRD §6.7, A07).
    |
    */

    'failed' => 'Identifiants incorrects.',
    'password' => 'Le mot de passe est incorrect.',
    'throttle' => 'Trop de tentatives de connexion. Réessayez dans :seconds secondes.',

    // Réponses des demandes de code (03-APPFLOW §2, F-AUTH-05).
    'code_sent' => 'Si ce compte existe, un code a été envoyé.',
    'phone_code_sent' => 'Un code a été envoyé au nouveau numéro.',

    /*
    |--------------------------------------------------------------------------
    | Messages envoyés (SMS et e-mail)
    |--------------------------------------------------------------------------
    |
    | Textes des codes à usage unique (F-AUTH-03, F-AUTH-05). Le code n'apparaît
    | que dans le message envoyé, jamais dans un journal.
    |
    */

    'messages' => [
        'password_reset' => [
            'sms' => 'CGA Connect : votre code de réinitialisation est :code. Il expire dans :minutes min. Ne le communiquez à personne.',
            'mail_subject' => 'Votre code de réinitialisation CGA Connect',
            'mail_line' => 'Votre code de réinitialisation du mot de passe est :code.',
            'mail_expiry' => 'Il expire dans :minutes minutes. Si vous n\'êtes pas à l\'origine de cette demande, ignorez ce message.',
        ],
        'phone_change' => [
            'sms' => 'CGA Connect : votre code de confirmation du nouveau numéro est :code. Il expire dans :minutes min.',
            'mail_subject' => 'Votre code de confirmation CGA Connect',
            'mail_line' => 'Votre code de confirmation du nouveau numéro est :code.',
            'mail_expiry' => 'Il expire dans :minutes minutes.',
        ],
    ],

];
