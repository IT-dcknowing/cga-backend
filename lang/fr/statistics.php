<?php

/*
|--------------------------------------------------------------------------
| Statistiques et tableaux de bord (PRD M15)
|--------------------------------------------------------------------------
*/

return [

    // Mention de couverture (RG-STA-01).
    'coverage' => '{0} Sur la base de :subscribed CGA abonné sur :total|{1} Sur la base de :subscribed CGA abonné sur :total|[2,*] Sur la base de :subscribed CGA abonnés sur :total',

    // Moyenne du réseau sur trop peu de CGA (RG-STA-04).
    'insufficient' => 'Données insuffisantes',

    // Période personnalisée trop longue (F-DSH-04).
    'period_too_long' => 'La période ne peut dépasser :days jours.',

    // Alertes des tableaux de bord (03-APPFLOW §3.2 et §4.3).
    'alerts' => [
        'reports_late' => '{0} Aucun rapport en retard|{1} 1 rapport en retard|[2,*] :count rapports en retard',
        'dues_unpaid' => '{0} Aucun CGA impayé|{1} 1 CGA impayé|[2,*] :count CGA impayés',
        'agreements_expiring' => '{0} Aucun agrément à échéance|{1} 1 agrément à échéance|[2,*] :count agréments à échéance',
        'trainings_almost_full' => '{0} Aucune formation presque complète|{1} 1 formation presque complète|[2,*] :count formations presque complètes',
        'rsi_active' => '{0} Aucun adhérent reclassé au réel|{1} 1 adhérent reclassé au réel|[2,*] :count adhérents reclassés au réel',
        'members_to_complete' => '{0} Aucun dossier à compléter|{1} 1 dossier à compléter|[2,*] :count dossiers à compléter',
        'pieces_pending' => '{0} Aucune pièce en attente|{1} 1 dossier avec des pièces en attente|[2,*] :count dossiers avec des pièces en attente',
        'fees_overdue' => '{0} Aucun honoraire impayé|{1} 1 adhérent avec des honoraires impayés|[2,*] :count adhérents avec des honoraires impayés',
        'radiations_pending' => '{0} Aucune radiation en attente de validation|{1} 1 radiation en attente de validation|[2,*] :count radiations en attente de validation',
        'invoices_pending' => '{0} Aucune facture en attente de certification|{1} 1 facture en attente de certification|[2,*] :count factures en attente de certification',
        'closing_late' => '{0} Clôture : aucun dossier en retard|{1} Clôture : 1 dossier en retard|[2,*] Clôture : :count dossiers en retard',
        'concerns_unanswered' => '{0} Aucune préoccupation sans réponse|{1} 1 préoccupation sans réponse|[2,*] :count préoccupations sans réponse',
    ],

    // Indicateur macro sans référence nationale de l'année (RG-STA-03).
    'macro_unavailable' => 'Non disponible — saisir les références :year',

];
