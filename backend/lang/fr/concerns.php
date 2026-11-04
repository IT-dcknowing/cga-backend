<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Préoccupations (PRD M10)
    |--------------------------------------------------------------------------
    |
    | Messages système du fil (changements de statut, transfert au CNCGA), auteurs
    | des messages copiés de la demande d'origine (sans les nommer, RG-SEC-01),
    | demande de document (F-DOC-06) et événements des notifications.
    |
    */

    'system' => [
        'status' => 'Statut de la demande : :status.',
        'rejected' => 'Demande rejetée. Motif : :reason',
        'reopened' => 'Demande rouverte par le demandeur.',
        'transferred' => 'Demande transmise au CNCGA.',
    ],

    'copied_authors' => [
        'requester' => 'Adhérent',
        'handler' => 'CGA',
    ],

    'document_request' => [
        'subject' => 'Demande de document : :type',
        'body' => 'Merci de me transmettre ce document : :type.',
    ],

    // « Votre préoccupation « … » :event. »
    'events' => [
        'reply' => 'a reçu une réponse',
        'status' => 'est passée au statut « :status »',
    ],

    // Activité du demandeur, pour qui traite la demande.
    'followups' => [
        'reply' => 'nouveau message',
        'reopened' => 'demande rouverte',
    ],

];
