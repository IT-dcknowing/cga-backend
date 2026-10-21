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

    // Organisations (F-ORG-02 à 07)
    'organization_suspended' => 'Votre CGA est suspendu : seule la consultation est possible.',
    'organization_not_suspended' => 'Ce CGA n\'est pas suspendu.',
    'organization_already_suspended' => 'Ce CGA est déjà suspendu.',
    'organization_terminated' => 'Ce CGA est résilié : il ne peut plus être modifié.',
    'agreement_already_withdrawn' => 'L\'agrément de ce CGA est déjà retiré.',
    'abbreviation_locked' => 'L\'abréviation de numérotation ne peut plus changer : des numéros d\'adhésion ont déjà été attribués.',
    'subscription_unchanged' => 'Ce CGA a déjà cet état d\'abonnement.',

    // Utilisateurs d'une organisation (F-ORG-09, F-ORG-10)
    'agent_already_member' => 'Cette personne a déjà un accès à votre organisation : modifiez plutôt ses rôles.',
    'cannot_manage_self' => 'Vous ne pouvez pas modifier ni désactiver votre propre accès.',
    'agent_already_disabled' => 'Cet accès est déjà désactivé.',
    'agent_not_disabled' => 'Cet accès n\'est pas désactivé.',
    'agent_not_invited' => 'Cette personne a déjà activé son accès : aucune invitation à renvoyer.',
    'cga_manager_exists' => 'Ce CGA a déjà un gérant, invité ou actif.',
    'user_disabled' => 'Le compte de cette personne est désactivé sur la plateforme.',
    'email_required' => 'Une adresse e-mail est nécessaire pour envoyer l\'invitation par e-mail.',

    // Dossiers des adhérents (M04)
    'ncc_already_registered' => 'Ce NCC a déjà un dossier ouvert dans votre centre : ouvrez-le (ou basculez-le) plutôt que d\'en créer un autre.',
    'membership_exclusive' => 'Ce contribuable est adhérent d\'un autre CGA. L\'inscription sera possible dès que sa radiation y aura été validée.',
    'member_not_draft' => 'Seul un brouillon se supprime : un dossier validé se radie.',
    'member_closed' => 'Ce dossier est radié ou clos : il ne se modifie plus. Un retour est une nouvelle adhésion.',
    'source_locked' => 'Cette valeur vient de Compta Flow, qui fait foi : elle se corrige dans Compta Flow.',

    // Référentiels (RG-DATA-05)
    'merge_same_value' => 'Une valeur ne se fusionne pas avec elle-même.',
    'merge_other_directorate' => 'Un CDI ne se fusionne que dans un CDI de la même direction régionale.',
    'merge_target_inactive' => 'La valeur cible doit être active (ou validée pour un secteur).',
    'referential_in_use' => 'Cette valeur est déjà utilisée : elle ne peut pas être supprimée. Désactivez-la ou fusionnez-la plutôt.',

    // Authentification (F-AUTH-01 à 05, 03-APPFLOW §2)
    'too_many_attempts' => 'Trop de tentatives, réessayez dans :minutes minutes.',
    'inactive_account' => 'Votre accès n\'est pas actif. Contactez votre CGA.',
    'wrong_code' => 'Code incorrect (:attempts_left essai(s) restant(s)).',
    'code_expired' => 'Ce code n\'est plus valide. Demandez un nouveau code.',
    'invitation_invalid' => 'Ce lien n\'est plus valide.',
    'phone_taken' => 'Ce numéro est déjà associé à un autre compte.',

];
