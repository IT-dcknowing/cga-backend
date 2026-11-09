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
    'membership_incomplete' => 'L\'adhésion ne peut pas encore être validée : complétez ce qui manque.',
    'sector_code_too_long' => 'Le format du code des secteurs proposés donne un code trop long : l\'administrateur de la plateforme doit corriger ce paramètre.',
    'abbreviation_missing' => 'Renseignez d\'abord l\'abréviation de numérotation du centre (fiche du CGA).',
    'member_not_draft' => 'Seul un brouillon se valide ou se supprime : un dossier validé se radie.',
    'member_draft' => 'Ce dossier est un brouillon : son régime se modifie directement dans la fiche.',
    'member_not_active' => 'Seul un dossier actif peut être radié.',
    'radiation_cga_only' => 'La radiation concerne le portefeuille CGA : un dossier du portefeuille Cabinet se clôt.',
    'radiation_already_requested' => 'Une radiation est déjà en cours pour ce dossier.',
    'radiation_not_requested' => 'Aucune radiation en cours à valider pour ce dossier.',
    'radiation_not_found' => 'Aucune radiation à annuler pour ce dossier.',
    'piece_not_applicable' => 'Cette pièce n\'est pas attendue pour ce dossier.',
    'member_access_exists' => 'Cette personne a déjà un accès en ligne à ce dossier.',
    'member_owner_exists' => 'Ce dossier a déjà son accès principal : invitez un accès secondaire.',
    'access_not_invited' => 'Cet accès n\'a pas d\'invitation en attente.',
    'access_already_disabled' => 'Cet accès est déjà désactivé.',
    'access_not_disabled' => 'Cet accès n\'est pas désactivé.',
    'transfer_source_not_found' => 'Aucun dossier de ce NCC dans un autre CGA abonné : inscrivez l\'adhérent normalement.',
    'portfolio_already_cga' => 'Ce dossier est déjà au portefeuille CGA.',
    // Obligations fiscales et sociales (M07)
    'obligation_type_unavailable' => 'Cette obligation n\'est pas proposée à votre centre : choisissez une obligation du catalogue en vigueur ou une obligation locale active.',
    'obligation_already_assigned' => 'Cette obligation est déjà attribuée à ce dossier.',
    'obligation_not_reviewable' => 'Cette obligation a déjà été traitée : rechargez le dossier.',
    'instance_not_declaration' => 'Seule une déclaration peut être marquée « faite ».',
    'instance_already_done' => 'Cette déclaration est déjà marquée « faite » ou n\'est plus à faire : rechargez le dossier.',
    'instance_not_payment' => 'Seule une échéance de paiement reçoit un montant dû ou un paiement.',
    'instance_not_diligence' => 'Seule une diligence change d\'avancement.',
    'instance_cancelled' => 'Cette échéance est annulée : elle ne se modifie plus.',
    'instance_already_paid' => 'Cette échéance est déjà payée.',
    'instance_not_declarable' => 'Une diligence ne se déclare pas depuis l\'espace adhérent : votre CGA en suit l\'avancement.',
    'closing_campaign_closed' => 'Cette campagne de clôture est close : elle ne se modifie plus.',
    'dues_already_invoiced' => 'Cette cotisation est déjà facturée : corrigez la facture par un avoir si besoin.',
    'dues_already_paid' => 'Cette cotisation est entièrement réglée.',
    'dues_payment_exceeds_balance' => 'Le paiement dépasse le reste dû (:balance).',
    'dues_payment_not_pending' => 'Ce paiement n\'attend plus de confirmation.',
    'campaign_not_draft' => 'Cet envoi groupé est déjà soumis.',
    'campaign_not_pending' => 'Cet envoi groupé n\'attend pas de validation.',
    'sms_quota_insufficient' => 'Quota de SMS insuffisant : il reste :remaining SMS ce mois-ci. Réduisez les destinataires ou retirez le SMS.',
    'report_exists' => 'Le rapport de ce trimestre existe déjà : ouvrez-le depuis la liste.',
    'report_not_generated' => 'Générez d\'abord le rapport : seule une version générée se transmet.',
    'report_not_transmitted' => 'Ce rapport n\'est pas encore transmis au SP-CGA.',
    'report_incomplete' => 'Le rapport n\'est pas complet : chaque section doit être à 100 % avant la génération.',
    'report_not_editable' => 'Ce rapport est transmis : il ne se modifie plus. Corrigez-le par une nouvelle version.',
    'closing_step_already_done' => 'Cette étape est déjà marquée faite pour ce dossier.',
    'closing_step_out_of_order' => 'Les étapes se font dans l\'ordre : marquez d\'abord l\'étape « :step ».',
    'member_declaration_pending' => 'Une déclaration attend déjà la confirmation du CGA pour cette ligne.',
    'member_declaration_missing' => 'Aucune déclaration de l\'adhérent n\'attend de confirmation sur cette ligne : rechargez le dossier.',
    'obligation_amount_unknown' => 'Saisissez d\'abord le montant dû de cette échéance.',
    'obligation_payment_exceeds_balance' => 'Le paiement dépasse le reste dû (:balance F CFA).',
    'obligation_amount_below_paid' => 'Le montant dû ne peut pas être inférieur au montant déjà payé (:paid F CFA).',

    'referrer_in_use' => 'Cet apporteur est rattaché à des dossiers : désactivez-le plutôt que de le supprimer.',
    'payment_not_pending' => 'Ce paiement n\'attend plus de confirmation.',
    'radiation_cancel_expired' => 'Le délai d\'annulation de cette radiation est dépassé.',
    'member_closed' => 'Ce dossier est radié ou clos : il ne se modifie plus. Un retour est une nouvelle adhésion.',
    'source_locked' => 'Cette valeur vient de Compta Flow, qui fait foi : elle se corrige dans Compta Flow.',

    // Documents officiels (M08)
    'cga_profile_incomplete' => 'Complétez la fiche du centre pour produire vos documents.',
    'template_missing' => 'Aucun modèle actif pour ce document : le CNCGA doit en activer un.',
    'contract_unavailable' => 'Le contrat d\'adhésion se produit pour un dossier validé du portefeuille CGA.',
    'contract_already_issued' => 'Le contrat de ce dossier est déjà émis : régénérez-le, ou relancez sa production si elle a échoué.',
    'document_not_failed' => 'Seule une production en échec se relance.',
    'certificate_unavailable' => 'L\'attestation d\'adhésion se délivre à un adhérent actif du portefeuille CGA : ni radié, ni en radiation.',
    'attestation_blocked' => 'La délivrance de l\'attestation est bloquée pour cet adhérent.',
    'certificate_already_issued' => 'L\'attestation de l\'année est déjà émise : rééditez-la (duplicata).',
    'receipt_unavailable' => 'Seul un paiement confirmé a un reçu.',
    'receipt_already_issued' => 'Le reçu de ce paiement est déjà émis.',
    'radiation_notice_unavailable' => 'Le courrier de notification se produit pour une radiation validée.',
    'radiation_notice_already_issued' => 'Le courrier de notification de cette radiation est déjà émis.',
    'document_not_regenerable' => 'Seuls le contrat et l\'attestation d\'adhésion se régénèrent.',
    'document_not_valid' => 'Ce document n\'est pas valide : il est en production, en échec ou déjà annulé.',
    'document_superseded' => 'Une version plus récente de ce document existe : régénérez la dernière.',
    'attestation_block_unavailable' => 'Seule l\'attestation d\'un adhérent validé du portefeuille CGA se bloque.',
    'attestation_already_blocked' => 'L\'attestation de cet adhérent est déjà bloquée.',
    'attestation_not_blocked' => 'L\'attestation de cet adhérent n\'est pas bloquée.',
    'certificate_campaign_empty' => 'Aucun adhérent n\'attend son attestation de l\'année.',
    'template_already_active' => 'Cette version du modèle est déjà active.',
    'certification_mode_unavailable' => 'Le mode de certification FNE du centre n\'est pas encore disponible : choisissez le mode manuel dans « Facturation FNE ».',
    'invoice_not_awaiting_manual_certification' => 'Seule une facture en attente de certification, en mode manuel, reçoit une certification saisie.',
    'invoice_not_resubmittable' => 'Seule une facture rejetée, ou restée en attente après le dernier essai d\'un service indisponible, se soumet de nouveau.',
    'invoice_not_cancellable' => 'Seule une facture jamais certifiée et qui n\'est pas en cours d\'envoi au service s\'annule ; une facture certifiée se corrige par un avoir.',
    'invoice_not_deliverable' => 'Cette facture n\'est pas certifiée, ou son PDF est déjà remis.',
    'credit_note_unavailable' => 'Un avoir ne s\'émet que sur une facture certifiée.',
    'credit_note_exists' => 'Cette facture a déjà un avoir.',
    'invoicing_test_unavailable' => 'Le mode manuel n\'appelle aucun service : rien à tester.',
    'template_variables_mismatch' => 'Les variables de cette version ne correspondent pas à celles que la plateforme remplit : corrigez le modèle et déposez-le de nouveau.',

    // Référentiels (RG-DATA-05)
    'merge_same_value' => 'Une valeur ne se fusionne pas avec elle-même.',
    'merge_other_directorate' => 'Un CDI ne se fusionne que dans un CDI de la même direction régionale.',
    'merge_target_inactive' => 'La valeur cible doit être active (ou validée pour un secteur).',
    'referential_in_use' => 'Cette valeur est déjà utilisée : elle ne peut pas être supprimée. Désactivez-la ou fusionnez-la plutôt.',

    // Préoccupations (F-PRE-02, 03)
    'concern_closed' => 'Cette demande est traitée ou rejetée : elle doit être rouverte avant tout nouveau message.',
    'concern_status_invalid' => 'Ce changement de statut n\'est pas possible depuis le statut actuel de la demande.',
    'concern_not_closed' => 'Seule une demande traitée ou rejetée peut être rouverte.',
    'concern_already_transferred' => 'Cette demande a déjà été transmise au CNCGA.',

    // Formations (F-FOR-01 à 04)
    'training_locked' => 'Une formation commencée, terminée ou annulée ne se modifie plus.',
    'training_capacity_below_registered' => 'Le nombre de places ne peut pas être inférieur au nombre d\'inscrits (:registered).',
    'training_not_draft' => 'Seule une formation en brouillon se publie.',
    'training_already_started' => 'Cette formation a déjà commencé.',
    'training_not_cancellable' => 'Une session clôturée ou une formation déjà annulée ne s\'annule plus.',
    'training_registration_closed' => 'Les inscriptions à cette formation sont closes.',
    'training_not_targeted' => 'Cette formation ne s\'adresse pas à ces personnes.',
    'training_already_registered' => 'Vous êtes déjà inscrit à cette formation.',
    'training_registration_not_active' => 'Cette inscription n\'est plus en cours.',
    'training_cancellation_closed' => 'La désinscription n\'est plus possible à moins de :days jours de la session.',
    'training_registration_not_pending' => 'Cette inscription n\'attend pas de validation.',
    'training_payment_not_due' => 'Aucun paiement n\'est attendu pour cette inscription.',
    'training_not_in_session' => 'L\'émargement et la clôture se font à partir du début d\'une session publiée.',
    'training_attendance_incomplete' => 'Émargez chaque inscrit avant de clôturer la session (:unmarked restant(s)).',
    'training_evaluation_closed' => 'Seule une personne présente évalue une session clôturée.',

    // Authentification (F-AUTH-01 à 05, 03-APPFLOW §2)
    'too_many_attempts' => 'Trop de tentatives, réessayez dans :minutes minutes.',
    'inactive_account' => 'Votre accès n\'est pas actif. Contactez votre CGA.',
    'wrong_code' => 'Code incorrect (:attempts_left essai(s) restant(s)).',
    'code_expired' => 'Ce code n\'est plus valide. Demandez un nouveau code.',
    'invitation_invalid' => 'Ce lien n\'est plus valide.',
    'phone_taken' => 'Ce numéro est déjà associé à un autre compte.',

];
