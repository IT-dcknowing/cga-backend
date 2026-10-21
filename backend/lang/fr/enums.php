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

    'portfolio' => [
        'cga' => 'Portefeuille CGA',
        'cabinet' => 'Portefeuille Cabinet',
    ],

    'member_status' => [
        'draft' => 'Brouillon',
        'active' => 'Actif',
        'radiation_pending' => 'Radiation en cours',
        'radiated' => 'Radié',
        'closed' => 'Clos',
    ],

    'entity_type' => [
        'company' => 'Personne morale',
        'individual' => 'Entreprise individuelle',
    ],

    'legal_form' => [
        'SARL' => 'SARL',
        'SARLU' => 'SARLU',
        'SAS' => 'SAS',
        'SASU' => 'SASU',
        'SA' => 'SA',
        'SNC' => 'SNC',
        'GIE' => 'GIE',
        'COOP' => 'Coopérative',
        'EI' => 'Entreprise individuelle',
        'OTHER' => 'Autre',
    ],

    'tax_regime' => [
        'RME' => 'RME — microentreprise',
        'TEE' => 'TEE — entreprenant',
        'RSI' => 'RSI — réel simplifié',
        'RNI' => 'RNI — réel normal',
    ],

    'fees_periodicity' => [
        'monthly' => 'Mensuelle',
        'quarterly' => 'Trimestrielle',
        'yearly' => 'Annuelle',
    ],

    'pieces_status' => [
        'pending' => 'Pièces en attente',
        'complete' => 'Pièces complètes',
    ],

    'piece_type' => [
        'rccm' => 'RCCM',
        'dfe' => 'DFE',
        'id_document' => 'Pièce d\'identité du dirigeant',
        'statutes' => 'Statuts',
        'location_plan' => 'Plan de localisation',
        'signed_cga_contract' => 'Contrat CGA signé',
    ],

    'piece_status' => [
        'expected' => 'Attendue',
        'received' => 'Reçue',
        'expired' => 'Expirée',
        'not_applicable' => 'Sans objet',
    ],

    'radiation_initiator' => [
        'cga' => 'CGA / cabinet',
        'member' => 'Contribuable',
        'sp_cga' => 'SP-CGA',
    ],

    'radiation_reason' => [
        'zone_change' => 'Changement de zone géographique',
        'follow_up_impossible' => 'Impossibilité matérielle de suivi',
        'serious_breach' => 'Manquements graves',
        'reclassified_real_regime' => 'Reclassement au régime réel',
        'cessation' => 'Cessation d\'activité',
        'business_sale' => 'Vente ou cession du fonds',
        'death' => 'Décès',
        'dissatisfaction' => 'Insatisfaction des prestations',
        'firm_change' => 'Changement de cabinet ou de CGA',
        'mutual_termination' => 'Rupture conventionnelle',
        'agreement_loss' => 'Perte d\'agrément du CGA',
        'other' => 'Autre',
    ],

    'radiation_status' => [
        'requested' => 'Radiation en cours',
        'validated' => 'Radiation validée',
        'cancelled' => 'Radiation annulée',
    ],

    'radiation_outcome' => [
        'closed' => 'Dossier clos',
        'moved_to_cabinet' => 'Basculé dans le portefeuille Cabinet',
    ],

    'revenue_kind' => [
        'forecast' => 'Prévisionnel',
        'actual' => 'Réalisé',
    ],

    'revenue_source' => [
        'entry' => 'Saisie',
        'import' => 'Import',
        'report' => 'Rapport',
        'compta_flow' => 'Compta Flow',
    ],

    'movement_direction' => [
        'cabinet_to_cga' => 'Cabinet vers CGA',
        'cga_to_cabinet' => 'CGA vers Cabinet',
    ],

    'fee_kind' => [
        'entry_fee' => 'Droit d\'adhésion',
        'fees' => 'Honoraires',
    ],

    'reference_dataset' => [
        'cgas' => 'Liste des CGA',
        'regional-directorates' => 'Directions régionales',
        'tax-offices' => 'Centres des impôts',
        'communes' => 'Communes',
        'national-references' => 'Références nationales',
    ],

    'import_mode' => [
        'update' => 'Mettre à jour',
        'replace' => 'Remplacer',
    ],

    'removal' => [
        'delete' => 'Supprimée',
        'deactivate' => 'Désactivée (utilisée)',
        'keep' => 'Gardée (utilisée)',
    ],

    'import_type' => [
        'cgas' => 'Registre des CGA',
        'members' => 'Portefeuille d\'adhérents',
        'revenues' => 'Chiffre d\'affaires',
        'tax_payments' => 'Paiements d\'impôts',
    ],

    'import_status' => [
        'queued' => 'En attente',
        'processing' => 'En cours',
        'done' => 'Terminé',
        'failed' => 'En échec',
    ],

    'import_row_status' => [
        'imported' => 'Importée',
        'draft' => 'À compléter',
        'rejected' => 'Rejetée',
    ],

    'cga_image_kind' => [
        'logo' => 'Logo',
        'signature' => 'Signature du gérant',
        'stamp' => 'Cachet',
    ],

    'organization_status_event' => [
        'suspended' => 'Suspension',
        'reactivated' => 'Réactivation',
        'agreement_withdrawn' => 'Retrait d\'agrément',
        'subscription_changed' => 'Changement d\'abonnement',
        'terminated' => 'Résiliation',
    ],

    'sector_status' => [
        'validated' => 'Validé',
        'proposed' => 'Proposé',
        'rejected' => 'Rejeté',
        'merged' => 'Fusionné',
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
