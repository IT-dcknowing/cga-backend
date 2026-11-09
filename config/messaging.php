<?php

/*
|--------------------------------------------------------------------------
| Comptes d'envoi (RG-NOT-05, F-ADM-06, 02-TRD §10)
|--------------------------------------------------------------------------
|
| Pilotes disponibles et champs de leur compte, saisis dans l'écran « Envois »
| de l'administrateur : paramètres (en clair) et identifiants (chiffrés,
| jamais réaffichés). Aucun identifiant de fournisseur dans .env.
|
*/

return [

    // Délai maximal d'un appel à un fournisseur (secondes).
    'http_timeout_seconds' => (int) env('MESSAGING_HTTP_TIMEOUT', 15),

    // Caractères des identifiants laissés visibles à l'écran (RG-NOT-05).
    'secret_visible_chars' => 4,

    'drivers' => [
        // Développement et recette : rien ne part, les messages vont au journal « sms » ou « mail ».
        'log' => [
            'channels' => ['sms', 'whatsapp', 'email'],
            'settings' => [],
            'secrets' => [],
        ],
        // API SMS d'Orange Côte d'Ivoire (jeton OAuth2, puis envoi depuis l'adresse expéditeur).
        'orange_ci' => [
            'channels' => ['sms'],
            'settings' => [
                'base_url' => ['required', 'url:https'],
                'sender_address' => ['required', 'string', 'max:30'],
                'sender_name' => ['nullable', 'string', 'max:11'],
                'cost_units' => ['nullable', 'integer', 'min:0'],
            ],
            'secrets' => [
                'client_id' => ['required', 'string', 'max:255'],
                'client_secret' => ['required', 'string', 'max:255'],
            ],
        ],
        // Agrégateur SMS configurable : adresse, en-tête de la clé, noms des champs envoyés, chemin de
        // l'identifiant dans la réponse.
        'http_generic' => [
            'channels' => ['sms'],
            'settings' => [
                'url' => ['required', 'url:https'],
                'key_header' => ['required', 'string', 'max:60'],
                'sender' => ['required', 'string', 'max:11'],
                'to_field' => ['required', 'string', 'max:40'],
                'text_field' => ['required', 'string', 'max:40'],
                'sender_field' => ['required', 'string', 'max:40'],
                'id_path' => ['nullable', 'string', 'max:100'],
                'cost_units' => ['nullable', 'integer', 'min:0'],
            ],
            'secrets' => [
                'api_key' => ['required', 'string', 'max:255'],
            ],
        ],
        // WhatsApp Business Cloud API (Meta) : modèles pré-approuvés {clé : {name, language}}.
        'whatsapp_cloud' => [
            'channels' => ['whatsapp'],
            'settings' => [
                'base_url' => ['required', 'url:https'],
                'phone_number_id' => ['required', 'string', 'max:40'],
                'templates' => ['nullable', 'array'],
                'templates.*.name' => ['required', 'string', 'max:100'],
                'templates.*.language' => ['required', 'string', 'max:10'],
            ],
            'secrets' => [
                'access_token' => ['required', 'string', 'max:1000'],
            ],
        ],
        // SMTP d'un fournisseur transactionnel.
        'smtp' => [
            'channels' => ['email'],
            'settings' => [
                'host' => ['required', 'string', 'max:255'],
                'port' => ['required', 'integer', 'between:1,65535'],
                'encryption' => ['nullable', 'in:tls,ssl'],
                'from_address' => ['required', 'email'],
                'from_name' => ['required', 'string', 'max:100'],
            ],
            'secrets' => [
                'username' => ['nullable', 'string', 'max:255'],
                'password' => ['nullable', 'string', 'max:255'],
            ],
        ],
    ],

    // Historique des campagnes affiché sur /cga/communications (F-COM-03) : les plus récentes.
    'campaign_history' => (int) env('MESSAGING_CAMPAIGN_HISTORY', 200),

];
