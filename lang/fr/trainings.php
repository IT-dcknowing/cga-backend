<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Formations et séminaires (PRD M11)
    |--------------------------------------------------------------------------
    |
    | Évolutions d'une inscription notifiées à la personne (« Formation « … » :
    | :event. »), titres et textes des documents d'une formation.
    |
    */

    'events' => [
        'registered' => 'inscription confirmée',
        'pending' => 'inscription en attente de validation par l\'organisateur',
        'waitlisted' => 'vous êtes en liste d\'attente (position :position)',
        'approved' => 'inscription validée et confirmée',
        'rejected' => 'inscription refusée par l\'organisateur',
        'promoted' => 'une place s\'est libérée, votre inscription est confirmée',
        'cancelled' => 'votre inscription a été annulée par votre centre',
        'training_cancelled' => 'la formation est annulée (:reason)',
        'reminder' => 'rappel, la session commence le :date',
        'certificate' => 'votre attestation de participation est disponible',
    ],

    'receipt_title' => 'Reçu de formation — :title',
    'certificate_title' => 'Attestation de participation — :title',
    'member_of' => 'Adhérent de :cga',
    'dates' => 'du :start au :end',
    'duration' => ':hours h :minutes',

];
