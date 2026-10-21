<?php

/*
|--------------------------------------------------------------------------
| Catalogue des permissions (02-TRD §6.2, 01-PRD §3.3)
|--------------------------------------------------------------------------
|
| Le catalogue vit dans le code ; l'attribution aux rôles est en base
| (role_permissions, réseau puis surcharge d'un CGA) et ajustable par
| utilisateur (user_permission_overrides). « roles » donne la matrice par
| défaut validée par le Directeur, chargée par PermissionSeeder et utilisée
| par « Revenir aux droits par défaut ». Une permission « locked » garde
| toujours sa valeur par défaut : aucun paramétrage ne peut la changer
| (RG-ROLE-04). Libellés : lang/fr/permissions.php.
|
| Droits de lecture distincts quand la matrice indique « L ». Les juristes
| et fiscalistes ont les droits de la colonne « Comptable / Juriste » ; le
| chargé des cotisations et le formateur ceux du PRD §3.1.
|
*/

$president = 'cncga_president';
$networkManager = 'cncga_manager';
$dues = 'cncga_dues';
$training = 'cncga_training';
$board = 'cncga_viewer';
$manager = 'cga_manager';
$expert = 'cga_accountant_expert';
$accountants = ['cga_accountant', 'cga_legal', 'cga_tax_advisor'];
$billing = 'cga_billing_officer';
$trainer = 'cga_trainer';
$secretary = 'cga_secretary';
$members = ['member_owner', 'member_user'];
$admin = 'platform_admin';

return [

    'permissions' => [

        // CNCGA et registre des CGA
        'organizations.view' => ['locked' => false, 'roles' => [$president, $networkManager, $board, $admin]],
        'organizations.manage' => ['locked' => false, 'roles' => [$president, $networkManager, $admin]],
        'organizations.suspend' => ['locked' => true, 'roles' => [$president]],
        'organizations.subscription.manage' => ['locked' => false, 'roles' => [$president, $networkManager, $admin]],
        'network_users.manage' => ['locked' => true, 'roles' => [$president]],
        'network_user_permissions.manage' => ['locked' => true, 'roles' => [$admin]],
        'network_permissions.manage' => ['locked' => true, 'roles' => [$admin]],
        'network_settings.manage' => ['locked' => false, 'roles' => [$president, $networkManager]],
        'referentials.manage' => ['locked' => false, 'roles' => [$president, $networkManager, $admin]],
        'dues.view' => ['locked' => false, 'roles' => [$president, $networkManager, $dues, $board]],
        'dues.payments.manage' => ['locked' => false, 'roles' => [$president, $networkManager, $dues]],
        'dues.remind' => ['locked' => false, 'roles' => [$president, $networkManager, $dues]],
        'announcements.network.publish' => ['locked' => false, 'roles' => [$president, $networkManager, $training]],
        'trainings.network.manage' => ['locked' => false, 'roles' => [$president, $networkManager, $training]],
        'network_concerns.view' => ['locked' => false, 'roles' => [$president, $networkManager, $board]],
        'network_concerns.handle' => ['locked' => false, 'roles' => [$president, $networkManager]],
        'reports.network.view' => ['locked' => false, 'roles' => [$president, $networkManager, $board]],
        'statistics.national.view' => ['locked' => false, 'roles' => [$president, $networkManager, $dues, $training, $board]],
        'exports.run' => ['locked' => false, 'roles' => [$president, $networkManager, $dues, $training, $manager, $expert]],
        'invoices.issue' => ['locked' => false, 'roles' => [$president, $networkManager, $dues, $manager, $expert, ...$accountants, $billing]],

        // Centre (CGA)
        'statistics.position.view' => ['locked' => false, 'roles' => [$manager, $expert]],
        'cga.profile.view' => ['locked' => false, 'roles' => [$manager, $expert]],
        'cga.profile.manage' => ['locked' => false, 'roles' => [$manager]],
        'cga.logs.view' => ['locked' => false, 'roles' => [$manager]],
        'cga_users.manage' => ['locked' => true, 'roles' => [$manager]],
        'cga_permissions.manage' => ['locked' => true, 'roles' => [$manager]],
        'cga_user_permissions.manage' => ['locked' => true, 'roles' => [$manager]],
        'integrations.view' => ['locked' => false, 'roles' => [$manager, $expert]],
        'integrations.manage' => ['locked' => false, 'roles' => [$manager]],
        'internal.manage' => ['locked' => false, 'roles' => [$manager, $expert]],

        // Adhérents et portefeuilles (chaque agent voit tous les adhérents du CGA, RG-ACC-07)
        'members.view' => ['locked' => false, 'roles' => [$manager, $expert, ...$accountants, $billing, $trainer, $secretary]],
        'members.create' => ['locked' => false, 'roles' => [$manager, $expert, ...$accountants, $secretary]],
        'members.update' => ['locked' => false, 'roles' => [$manager, $expert, ...$accountants]],
        'members.access.manage' => ['locked' => false, 'roles' => [$manager]],
        'members.import' => ['locked' => false, 'roles' => [$manager]],
        'members.radiation.request' => ['locked' => false, 'roles' => [$manager, $expert]],
        'members.radiation.validate' => ['locked' => false, 'roles' => [$manager, $expert]],
        'members.radiation.cancel' => ['locked' => true, 'roles' => [$manager]],
        'members.portfolio.move' => ['locked' => false, 'roles' => [$manager, $expert]],
        'members.cabinet.view' => ['locked' => false, 'roles' => [$manager, $expert, ...$accountants, $secretary]],
        'members.referrers.view' => ['locked' => false, 'roles' => [$manager, $expert]],

        // Documents, paiements, obligations, clôture
        'documents.produce' => ['locked' => false, 'roles' => [$manager, $expert, ...$accountants, $secretary]],
        'documents.cancel' => ['locked' => false, 'roles' => [$manager, $expert]],
        'attestations.block' => ['locked' => false, 'roles' => [$manager]],
        'member_payments.manage' => ['locked' => false, 'roles' => [$manager, $expert, ...$accountants, $billing]],
        'fees.remind' => ['locked' => false, 'roles' => [$manager, $expert, ...$accountants, $billing]],
        'obligations.manage' => ['locked' => false, 'roles' => [$manager, $expert, ...$accountants]],
        'closing.view' => ['locked' => false, 'roles' => [$manager, $expert, ...$accountants, $secretary]],
        'closing.manage' => ['locked' => false, 'roles' => [$manager, $expert, ...$accountants]],

        // Rapport, communication, préoccupations, formations, bancabilité
        'reports.prepare' => ['locked' => false, 'roles' => [$manager, $expert, ...$accountants, $secretary]],
        'reports.transmit' => ['locked' => false, 'roles' => [$manager, $expert]],
        'communications.bulk.send' => ['locked' => false, 'roles' => [$manager, $expert, ...$accountants]],
        'communications.bulk.approve' => ['locked' => false, 'roles' => [$manager]],
        'concerns.reply' => ['locked' => false, 'roles' => [$manager, $expert, ...$accountants, $secretary]],
        'concerns.network.write' => ['locked' => false, 'roles' => [$manager, $expert]],
        'trainings.cga.manage' => ['locked' => false, 'roles' => [$manager, $expert, ...$accountants, $secretary, $trainer]],
        'banking.manage' => ['locked' => false, 'roles' => [$manager, $expert, ...$accountants]],

        // Espace adhérent (le consentement bancaire est réservé à l'accès principal, RG-ROLE-04)
        'member.obligations.view' => ['locked' => false, 'roles' => $members],
        'member.obligations.declare' => ['locked' => false, 'roles' => $members],
        'member.payments.declare' => ['locked' => false, 'roles' => $members],
        'member.documents.view' => ['locked' => false, 'roles' => $members],
        'member.closing.view' => ['locked' => false, 'roles' => $members],
        'member.concerns.write' => ['locked' => false, 'roles' => $members],
        'member.trainings.register' => ['locked' => false, 'roles' => $members],
        'member.banking.view' => ['locked' => false, 'roles' => $members],
        'member.banking.consent' => ['locked' => true, 'roles' => ['member_owner']],

        // Administration de la plateforme
        'platform.settings.manage' => ['locked' => false, 'roles' => [$admin]],
        'platform.messaging.manage' => ['locked' => false, 'roles' => [$admin]],
        'platform.templates.manage' => ['locked' => false, 'roles' => [$admin]],
        'platform.logs.view' => ['locked' => false, 'roles' => [$admin]],

    ],

    /*
     * Modules de l'interface d'un rôle (F-ADM-05) : chaque droit y est rangé d'après le début de son
     * code. Libellés : lang/fr/permission_modules.php.
     */
    'modules' => [
        'network' => ['organizations', 'network_users', 'network_user_permissions', 'network_permissions', 'network_settings', 'referentials'],
        'centre' => ['cga', 'cga_users', 'cga_permissions', 'cga_user_permissions', 'integrations', 'internal'],
        'members' => ['members'],
        'documents' => ['documents', 'attestations'],
        'payments' => ['member_payments', 'fees', 'invoices'],
        'obligations' => ['obligations', 'closing'],
        'reports' => ['reports', 'statistics', 'exports'],
        'dues' => ['dues'],
        'concerns' => ['concerns', 'network_concerns'],
        'trainings' => ['trainings'],
        'communications' => ['communications', 'announcements'],
        'banking' => ['banking'],
        'member_space' => ['member'],
        'platform' => ['platform'],
    ],

];
