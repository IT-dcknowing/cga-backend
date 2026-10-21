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

    // Invitation d'un agent ou d'un gérant (RG-ACC-03, F-ORG-09) et nouvel accès d'un compte actif (RG-ACC-02).
    'invitation' => [
        'sms' => 'CGA Connect : :organization vous invite. Activez votre accès (lien valable :days jours) : :link',
        'mail_subject' => ':organization vous invite sur CGA Connect',
        'mail_line' => ':organization vous a ouvert un accès à CGA Connect. Choisissez votre mot de passe pour l\'activer.',
        'mail_action' => 'Activer mon accès',
        'mail_expiry' => 'Ce lien est valable :days jours. Passé ce délai, demandez un nouveau lien à :organization.',
    ],
    'access_granted' => [
        'sms' => 'CGA Connect : vous avez désormais accès à l\'espace :organization (:roles). Choisissez-le à votre prochaine connexion.',
        'mail_subject' => 'Nouvel accès à CGA Connect : :organization',
        'mail_line' => 'Vous avez désormais accès à l\'espace :organization (:roles). Choisissez-le dans le sélecteur d\'espace à votre prochaine connexion.',
    ],

];
