<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Notifications (PRD M13)
    |--------------------------------------------------------------------------
    |
    | Textes de chaque type déclaré dans config/notifications.php : titre (plateforme
    | et objet de l'e-mail), texte, SMS (160 caractères visés).
    |
    */

    'open' => 'Ouvrir dans CGA Connect',
    'test_message' => 'Message de test de CGA Connect : le compte d\'envoi fonctionne.',
    'driver_channel' => 'Ce pilote ne sert pas ce canal.',
    'test_subject' => 'CGA Connect : message de test',
    'quota_exhausted' => 'Quota épuisé : le quota mensuel de SMS du centre est atteint.',

    'outcomes' => [
        'confirmed' => 'confirmé',
        'rejected' => 'rejeté',
    ],

    'types' => [
        'document_available' => [
            'title' => 'Nouveau document disponible',
            'body' => 'Le document « :title » est disponible dans votre espace.',
            'sms' => 'CGA Connect : le document « :title » est disponible dans votre espace.',
        ],
        'obligation_due' => [
            'title' => 'Échéance le :date',
            'body' => ':label (:period) arrive à échéance le :date.',
            'sms' => 'CGA Connect : :label (:period) à régler ou déclarer avant le :date.',
        ],
        'obligation_overdue' => [
            'title' => 'Échéance dépassée',
            'body' => ':label (:period) de :member n\'est pas réglée : l\'échéance était le :date.',
            'sms' => 'CGA Connect : :label (:period) non réglée, échéance du :date dépassée.',
        ],
        'fees_unpaid' => [
            'title' => 'Honoraires impayés',
            'body' => ':count échéance(s) d\'honoraires restent impayées, pour :amount au total.',
            'sms' => 'CGA Connect : :count échéance(s) d\'honoraires impayées (:amount). Merci de régulariser.',
        ],
        'pieces_pending' => [
            'title' => 'Pièces en attente',
            'body' => ':count pièce(s) du dossier :member sont toujours attendues.',
            'sms' => 'CGA Connect : :count pièce(s) du dossier :member sont attendues.',
        ],
        'concern_new' => [
            'title' => 'Nouvelle préoccupation',
            'body' => ':from a déposé une préoccupation : « :subject ».',
            'sms' => 'CGA Connect : nouvelle préoccupation de :from.',
        ],
        'concern_updated' => [
            'title' => 'Préoccupation mise à jour',
            'body' => 'Votre préoccupation « :subject » :event.',
            'sms' => 'CGA Connect : votre préoccupation a évolué.',
        ],
        'concern_followup' => [
            'title' => 'Préoccupation : :event',
            'body' => ':from, préoccupation « :subject » : :event.',
            'sms' => 'CGA Connect : :event sur une préoccupation de :from.',
        ],
        'concern_stale' => [
            'title' => 'Préoccupations sans réponse',
            'body' => ':count demande(s) d\'adhérent attendent une réponse depuis plus de :days jours.',
            'sms' => 'CGA Connect : :count préoccupation(s) sans réponse depuis :days jours.',
        ],
        'training_published' => [
            'title' => 'Nouvelle formation',
            'body' => '« :title », le :date : les inscriptions sont ouvertes.',
            'sms' => 'CGA Connect : nouvelle formation « :title » le :date.',
        ],
        'training_registration' => [
            'title' => 'Formation « :title »',
            'body' => 'Formation « :title » : :event.',
            'sms' => 'CGA Connect, formation « :title » : :event.',
        ],
        'export_ready' => [
            'title' => 'Export prêt',
            'body' => 'Votre export « :list » est prêt à télécharger.',
            'sms' => 'CGA Connect : export « :list » prêt.',
        ],
        'announcement_published' => [
            'title' => 'Nouvelle annonce',
            'body' => ':author : « :title ».',
            'sms' => 'CGA Connect : nouvelle annonce de :author.',
        ],
        'dues_reminder' => [
            'title' => 'Cotisation :year à régler',
            'body' => 'La cotisation :year du CGA au CNCGA reste due (:amount), :days jours après l\'échéance du :date.',
            'sms' => 'CGA Connect : cotisation :year au CNCGA due (:amount).',
        ],
        'dues_payment_reviewed' => [
            'title' => 'Paiement de cotisation :outcome',
            'body' => 'Le paiement de :amount de la cotisation :year a été :outcome par le CNCGA.',
            'sms' => 'CGA Connect : paiement de cotisation :year :outcome.',
        ],
        'dues_payment_declared' => [
            'title' => 'Paiement de cotisation déclaré',
            'body' => ':cga a déclaré un paiement de :amount pour la cotisation :year, à confirmer.',
            'sms' => 'CGA Connect : :cga a déclaré un paiement de cotisation (:amount).',
        ],
        'dues_alert' => [
            'title' => 'Cotisation :year impayée depuis :days jours',
            'body' => 'La cotisation :year de :cga reste due (:amount) :days jours après la date butoir. Vous pouvez décider d\'une suspension ; rien n\'est fait automatiquement.',
            'sms' => 'CGA Connect : cotisation :year de :cga impayée depuis :days jours.',
        ],
        'campaign_message' => ['title' => ':title', 'body' => ':body', 'sms' => ':body'],
        'campaign_pending' => [
            'title' => 'Envoi groupé à valider',
            'body' => 'L\'envoi « :title » à :count destinataires attend votre validation.',
            'sms' => 'CGA Connect : envoi groupé « :title » à valider.',
        ],
        'report_reminder' => [
            'title' => 'Rapport :label à transmettre',
            'body' => 'Le rapport trimestriel :label n\'est pas encore transmis au SP-CGA.',
            'sms' => 'CGA Connect : rapport trimestriel :label à transmettre au SP-CGA.',
        ],
        'report_late' => [
            'title' => 'Rapport :label en retard',
            'body' => 'La date limite du rapport :label est passée : il n\'est pas encore transmis.',
            'sms' => 'CGA Connect : rapport :label en retard.',
        ],
        'report_legally_late' => [
            'title' => 'Rapport :label hors délai réglementaire',
            'body' => 'Le rapport :label de :cga n\'est pas transmis à l\'échéance réglementaire.',
            'sms' => 'CGA Connect : rapport :label de :cga hors délai réglementaire.',
        ],
        'report_transmitted' => [
            'title' => 'Rapport :label transmis',
            'body' => ':cga a enregistré la transmission de son rapport :label.',
            'sms' => 'CGA Connect : :cga a transmis son rapport :label.',
        ],
        'closing_pieces_reminder' => [
            'title' => 'Pièces de clôture :year',
            'body' => 'Merci de remettre à votre CGA les pièces comptables de l\'exercice :year avant le :due.',
            'sms' => 'CGA Connect : pièces de clôture :year attendues avant le :due.',
        ],
        'closing_steps_late' => [
            'title' => 'Clôture :year : dossiers en retard',
            'body' => ':count dossier(s) de la campagne de clôture :year sont en retard sur une étape.',
            'sms' => 'CGA Connect : :count dossier(s) en retard dans la clôture :year.',
        ],
        'radiation_requested' => [
            'title' => 'Radiation demandée',
            'body' => 'La radiation du dossier :member est demandée, avec effet au :date.',
            'sms' => 'CGA Connect : radiation du dossier :member demandée (effet au :date).',
        ],
        'radiation_validated' => [
            'title' => 'Radiation validée',
            'body' => 'La radiation du dossier :member est validée, avec effet au :date.',
            'sms' => 'CGA Connect : radiation du dossier :member validée (effet au :date).',
        ],
        'invoice_certified' => [
            'title' => 'Facture :number disponible',
            'body' => 'La facture :number, certifiée FNE, est disponible dans votre espace.',
            'sms' => 'CGA Connect : facture :number certifiée disponible.',
        ],
        'invoice_rejected' => [
            'title' => 'Facture :number rejetée par la DGI',
            'body' => 'La certification de la facture :number a été rejetée : corrigez-la puis resoumettez-la.',
            'sms' => 'CGA Connect : facture :number rejetée par la DGI.',
        ],
        'flow_sync_failed' => ['title' => 'Synchronisation Compta Flow en échec', 'body' => 'La synchronisation avec Compta Flow a échoué.', 'sms' => 'CGA Connect : synchronisation Compta Flow en échec.'],
        'payment_declared' => [
            'title' => 'Paiement déclaré par :member',
            'body' => ':member a déclaré un paiement de :amount, à confirmer.',
            'sms' => 'CGA Connect : :member a déclaré un paiement de :amount.',
        ],
        'payment_reviewed' => [
            'title' => 'Paiement :outcome',
            'body' => 'Votre paiement de :amount a été :outcome par votre CGA.',
            'sms' => 'CGA Connect : votre paiement de :amount a été :outcome.',
        ],
        'agreement_expiring' => [
            'title' => 'Agrément de :cga à renouveler',
            'body' => 'L\'agrément de :cga arrive à échéance le :date (dans :months mois).',
            'sms' => 'CGA Connect : agrément de :cga à échéance le :date.',
        ],
        'rsi_not_radiated' => [
            'title' => 'Dossier RSI ou RNI au portefeuille CGA',
            'body' => ':member est au régime réel depuis :days jours et reste au portefeuille CGA : radiation à envisager.',
            'sms' => 'CGA Connect : :member au réel depuis :days jours, toujours au portefeuille CGA.',
        ],
        'fees_unpaid_alert' => [
            'title' => 'Honoraires impayés depuis :months mois',
            'body' => ':member a des honoraires impayés depuis plus de :months mois. Aucune mesure n\'est prise automatiquement.',
            'sms' => 'CGA Connect : :member, honoraires impayés depuis :months mois.',
        ],
    ],

];
