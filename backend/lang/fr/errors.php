<?php

/*
|--------------------------------------------------------------------------
| Messages des erreurs renvoyées par l'API (02-TRD §4.3, §4.4)
|--------------------------------------------------------------------------
|
| Une ligne par code d'erreur renvoyé au client (champ « code »). Jamais de
| détail technique : celui-ci ne va qu'au journal d'erreurs.
|
*/

return [

    // Refus et erreurs génériques
    'unauthenticated' => 'Connectez-vous pour continuer.',
    'forbidden' => 'Vous n\'avez pas le droit de faire cette action.',
    'not_found' => 'Élément introuvable.',
    'method_not_allowed' => 'Cette action n\'est pas disponible à cette adresse.',
    'csrf_mismatch' => 'Votre session a expiré. Rechargez la page et recommencez.',
    'throttled' => 'Trop de tentatives. Patientez avant de réessayer.',
    'external_service_unavailable' => 'Service externe indisponible, réessayez.',
    'service_unavailable' => 'Service momentanément indisponible, réessayez.',
    'http_error' => 'La demande n\'a pas pu être traitée.',
    'server_error' => 'Une erreur est survenue (réf. :reference).',

    // Règles métier (409)
    'space_required' => 'Choisissez d\'abord un espace de travail.',
    'locked_permission' => 'Ce droit est verrouillé : il ne peut pas être modifié.',
    'unknown_permission' => 'Ce droit n\'existe pas.',
    'setting_not_editable' => 'Ce paramètre ne peut pas être modifié à ce niveau.',

    // Référentiels (RG-DATA-05)
    'referential_in_use' => 'Cette valeur est déjà utilisée : elle ne peut pas être supprimée. Désactivez-la ou fusionnez-la plutôt.',

    // Authentification (F-AUTH-01 à 05, 03-APPFLOW §2)
    'too_many_attempts' => 'Trop de tentatives, réessayez dans :minutes minutes.',
    'inactive_account' => 'Votre accès n\'est pas actif. Contactez votre CGA.',
    'wrong_code' => 'Code incorrect (:attempts_left essai(s) restant(s)).',
    'code_expired' => 'Ce code n\'est plus valide. Demandez un nouveau code.',
    'invitation_invalid' => 'Ce lien n\'est plus valide.',
    'phone_taken' => 'Ce numéro est déjà associé à un autre compte.',

];
