<?php

/*
|--------------------------------------------------------------------------
| Libellés des permissions (config/permissions.php, 01-PRD §3.3)
|--------------------------------------------------------------------------
*/

return [

    'organizations' => [
        'view' => 'Consulter le registre des CGA',
        'manage' => 'Enregistrer ou modifier un CGA',
        'suspend' => 'Suspendre ou réactiver un CGA',
        'subscription' => ['manage' => 'Gérer l\'abonnement d\'un CGA'],
    ],
    'network_users' => ['manage' => 'Gérer les utilisateurs du CNCGA'],
    'network_user_permissions' => ['manage' => 'Ajuster les habilitations d\'un utilisateur du CNCGA'],
    'network_permissions' => ['manage' => 'Gérer les droits de tout le réseau'],
    'network_settings' => ['manage' => 'Paramétrer les règles du réseau (rapport, cotisation, bancabilité)'],
    'referentials' => ['manage' => 'Gérer les données de référence'],
    'dues' => [
        'view' => 'Consulter les cotisations',
        'payments' => ['manage' => 'Enregistrer ou confirmer un paiement de cotisation'],
        'remind' => 'Relancer une cotisation',
    ],
    'announcements' => ['network' => ['publish' => 'Publier une annonce réseau']],
    'trainings' => [
        'network' => ['manage' => 'Créer une formation réseau'],
        'cga' => ['manage' => 'Créer une formation pour ses adhérents et agents'],
    ],
    'network_concerns' => [
        'view' => 'Consulter les préoccupations des CGA',
        'handle' => 'Traiter une préoccupation d\'un CGA',
    ],
    'reports' => [
        'network' => ['view' => 'Consulter l\'état de transmission et le tableau de synthèse des rapports'],
        'prepare' => 'Préparer le rapport trimestriel',
        'transmit' => 'Transmettre le rapport',
    ],
    'statistics' => [
        'national' => ['view' => 'Consulter les statistiques nationales'],
        'position' => ['view' => 'Consulter la position du CGA dans les statistiques nationales'],
    ],
    'exports' => ['run' => 'Exporter des listes'],
    'invoices' => ['issue' => 'Émettre une facture et la soumettre à la certification FNE'],
    'cga' => [
        'profile' => [
            'view' => 'Consulter la fiche et les paramètres du CGA',
            'manage' => 'Modifier la fiche et les paramètres du CGA',
        ],
        'logs' => ['view' => 'Consulter le journal du CGA'],
    ],
    'cga_users' => ['manage' => 'Gérer les utilisateurs du CGA'],
    'cga_permissions' => ['manage' => 'Ajuster les droits des rôles de son CGA'],
    'cga_user_permissions' => ['manage' => 'Ajuster les habilitations d\'un utilisateur de son CGA'],
    'integrations' => [
        'view' => 'Consulter la facturation FNE et la connexion Compta Flow',
        'manage' => 'Paramétrer la facturation FNE et la connexion Compta Flow du CGA',
    ],
    'internal' => ['manage' => 'Gestion interne du CGA (fiche permanente, obligations propres)'],
    'members' => [
        'view' => 'Consulter les adhérents',
        'create' => 'Inscrire un adhérent',
        'update' => 'Modifier un adhérent',
        'access' => ['manage' => 'Inviter les adhérents en ligne et gérer leurs accès'],
        'import' => 'Importer un portefeuille d\'adhérents',
        'radiation' => [
            'request' => 'Demander une radiation',
            'validate' => 'Valider une radiation',
            'cancel' => 'Annuler une radiation (30 jours)',
        ],
        'portfolio' => ['move' => 'Basculer un dossier entre portefeuilles'],
        'cabinet' => ['view' => 'Voir et gérer le portefeuille Cabinet'],
        'referrers' => ['view' => 'Voir les apporteurs d\'affaires (« Accessoires »)'],
    ],
    'documents' => [
        'produce' => 'Produire contrat et attestation',
        'cancel' => 'Annuler un document émis',
    ],
    'attestations' => ['block' => 'Bloquer ou débloquer l\'attestation d\'un adhérent'],
    'member_payments' => ['manage' => 'Enregistrer ou confirmer un paiement d\'adhérent'],
    'fees' => ['remind' => 'Relancer les honoraires impayés'],
    'obligations' => ['manage' => 'Mettre à jour les obligations d\'un adhérent'],
    'closing' => [
        'view' => 'Consulter la campagne de clôture',
        'manage' => 'Suivre la campagne de clôture (SMT, états financiers)',
    ],
    'communications' => [
        'bulk' => [
            'send' => 'Envoyer un rappel groupé',
            'approve' => 'Valider un envoi groupé de plus de 50 destinataires',
        ],
    ],
    'concerns' => [
        'reply' => 'Répondre aux préoccupations des adhérents',
        'network' => ['write' => 'Écrire au CNCGA ou lui transférer une demande'],
    ],
    'banking' => ['manage' => 'Bancabilité (si activée)'],
    'member' => [
        'obligations' => [
            'view' => 'Consulter ses obligations',
            'declare' => 'Déclarer une échéance faite ou payée',
        ],
        'payments' => ['declare' => 'Déclarer un paiement à son CGA'],
        'documents' => ['view' => 'Consulter ses documents'],
        'closing' => ['view' => 'Voir les pièces à remettre pour la clôture'],
        'concerns' => ['write' => 'Écrire à son CGA'],
        'trainings' => ['register' => 'S\'inscrire à une formation'],
        'banking' => [
            'view' => 'Consulter sa bancabilité',
            'consent' => 'Donner son consentement bancaire',
        ],
    ],
    'platform' => [
        'settings' => ['manage' => 'Gérer les paramètres techniques de la plateforme'],
        'messaging' => ['manage' => 'Gérer les comptes d\'envoi SMS, WhatsApp et e-mail'],
        'templates' => ['manage' => 'Gérer les modèles de documents'],
        'logs' => ['view' => 'Consulter les journaux de la plateforme'],
    ],

];
