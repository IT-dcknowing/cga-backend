<?php

/*
|--------------------------------------------------------------------------
| Libellés des listes de valeurs (02-TRD §7.6)
|--------------------------------------------------------------------------
|
| Un groupe par enum (nom en snake_case), une ligne par valeur. Le test
| EnumLabelsTest vérifie que chaque valeur a son libellé.
|
*/

return [

    // Rôles (01-PRD §3.1)
    'role' => [
        'platform_admin' => 'Administrateur de la plateforme',
        'cncga_president' => 'Président(e)',
        'cncga_manager' => 'Gestionnaire / secrétariat',
        'cncga_dues' => 'Responsable des cotisations',
        'cncga_training' => 'Responsable des formations',
        'cncga_viewer' => 'Consultation (membre du bureau)',
        'cga_manager' => 'Gérant',
        'cga_accountant_expert' => 'Expert-comptable',
        'cga_accountant' => 'Comptable',
        'cga_legal' => 'Juriste',
        'cga_tax_advisor' => 'Fiscaliste',
        'cga_billing_officer' => 'Chargé des cotisations et honoraires',
        'cga_trainer' => 'Formateur',
        'cga_secretary' => 'Secrétaire / archiviste',
        'member_owner' => 'Accès principal (dirigeant)',
        'member_user' => 'Accès secondaire',
    ],

    'membership_status' => [
        'invited' => 'Invité',
        'active' => 'Actif',
        'read_only' => 'Consultation seule',
        'disabled' => 'Désactivé',
    ],

    'user_status' => [
        'invited' => 'Invité',
        'active' => 'Actif',
        'disabled' => 'Désactivé',
        'deleted' => 'Supprimé',
    ],

    'invitation_channel' => [
        'sms' => 'SMS',
        'email' => 'E-mail',
        'sms_email' => 'SMS et e-mail',
    ],

    'otp_purpose' => [
        'password_reset' => 'Mot de passe oublié',
        'phone_change' => 'Changement de téléphone',
    ],

    'preference_channel' => [
        'sms' => 'SMS',
        'whatsapp' => 'WhatsApp',
    ],

    'organization_type' => [
        'platform' => 'Plateforme',
        'cncga' => 'CNCGA',
        'cga' => 'CGA',
    ],

    'organization_status' => [
        'active' => 'Actif',
        'suspended' => 'Suspendu',
        'terminated' => 'Résilié',
    ],

    'agreement_status' => [
        'pending' => 'Agrément en cours',
        'granted' => 'Agréé',
        'withdrawn' => 'Agrément retiré',
    ],

    'subscription_status' => [
        'none' => 'Sans abonnement',
        'trial' => 'Essai',
        'active' => 'Abonné',
        'terminated' => 'Abonnement résilié',
    ],

    'data_status' => [
        'provisional' => 'Provisoire',
        'validated' => 'Validé',
    ],

];
