<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Droit d'adhésion, honoraires et paiements (M05)
    |--------------------------------------------------------------------------
    */

    'amount_exceeds_balance' => 'Le montant dépasse le reste dû des sommes choisies (:balance F CFA).',
    'charge_not_open' => 'Choisissez des sommes encore dues de ce dossier.',

    /*
    |--------------------------------------------------------------------------
    | Factures certifiées FNE (F-PAY-05, F-PAY-06)
    |--------------------------------------------------------------------------
    */

    'charges_not_invoiceable' => 'Choisissez des sommes dues de ce dossier, ni annulées ni déjà facturées.',
    'invoice_titles' => [
        'invoice' => 'FACTURE',
        'credit_note' => 'FACTURE D\'AVOIR',
    ],
    'credit_note_reference' => 'Avoir sur la facture n° :number du :date',
    'fne_unavailable' => 'Service de certification indisponible : nouvel essai programmé.',
    'fne_rejected_without_reason' => 'Facture rejetée par le service de certification, sans motif retourné.',
    'fne_number_format' => 'Ce numéro FNE n\'a pas le format attendu par le paramétrage « Facturation FNE » du centre.',
    'fne_credentials_required' => 'Saisissez les identifiants du service de certification.',

];
