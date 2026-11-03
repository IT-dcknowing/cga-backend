<?php

/*
|--------------------------------------------------------------------------
| Types de notifications (PRD M13, 02-TRD §10)
|--------------------------------------------------------------------------
|
| Pour chaque type : canaux prévus (database = dans la plateforme, mail,
| sms, whatsapp) ; essential : le destinataire ne peut pas refuser ses SMS
| et WhatsApp (échéances, radiation…, RG-NOT-04) ; contractual : sans
| e-mail, l'e-mail est remplacé par un SMS (RG-NOT-03) ; whatsapp_template :
| clé du modèle WhatsApp pré-approuvé (nom et langue réels dans le compte
| d'envoi, écran « Envois »). Textes : lang/fr/notifications.php.
|
*/

return [

    // File des notifications (02-TRD §10).
    'queue' => env('NOTIFICATIONS_QUEUE', 'notifications'),

    'types' => [
        'document_available' => ['channels' => ['database', 'mail'], 'essential' => false],
        'obligation_due' => ['channels' => ['database', 'mail', 'sms', 'whatsapp'], 'essential' => true, 'whatsapp_template' => 'echeance_rappel'],
        'obligation_overdue' => ['channels' => ['database', 'mail'], 'essential' => true, 'contractual' => true],
        'fees_unpaid' => ['channels' => ['database', 'mail', 'sms'], 'essential' => true],
        'pieces_pending' => ['channels' => ['database', 'mail'], 'essential' => false],
        'concern_new' => ['channels' => ['database', 'mail'], 'essential' => false],
        'concern_updated' => ['channels' => ['database', 'mail'], 'essential' => false],
        'concern_stale' => ['channels' => ['database'], 'essential' => false],
        'training_published' => ['channels' => ['database', 'mail'], 'essential' => false],
        'training_registration' => ['channels' => ['database', 'mail', 'sms'], 'essential' => false],
        'announcement_published' => ['channels' => ['database', 'mail'], 'essential' => false],
        'dues_reminder' => ['channels' => ['database', 'mail'], 'essential' => true],
        'dues_payment_reviewed' => ['channels' => ['database', 'mail'], 'essential' => true],
        'dues_payment_declared' => ['channels' => ['database'], 'essential' => false],
        'dues_alert' => ['channels' => ['database', 'mail'], 'essential' => true],
        // Envoi groupé (F-COM-03) : rappel non essentiel, les canaux de la campagne dans la limite du consentement.
        'campaign_message' => ['channels' => ['database', 'mail', 'sms', 'whatsapp'], 'essential' => false],
        'campaign_pending' => ['channels' => ['database', 'mail'], 'essential' => false],
        'report_reminder' => ['channels' => ['database', 'mail', 'sms'], 'essential' => true],
        'report_late' => ['channels' => ['database', 'mail'], 'essential' => true],
        'report_legally_late' => ['channels' => ['database', 'mail'], 'essential' => true],
        'report_transmitted' => ['channels' => ['database'], 'essential' => false],
        'closing_pieces_reminder' => ['channels' => ['database', 'mail', 'sms', 'whatsapp'], 'essential' => true, 'whatsapp_template' => 'cloture_pieces'],
        'closing_steps_late' => ['channels' => ['database', 'mail', 'sms', 'whatsapp'], 'essential' => true, 'whatsapp_template' => 'cloture_retards'],
        'radiation_requested' => ['channels' => ['database', 'mail', 'sms'], 'essential' => true, 'contractual' => true],
        'radiation_validated' => ['channels' => ['database', 'mail', 'sms'], 'essential' => true, 'contractual' => true],
        'invoice_certified' => ['channels' => ['database', 'mail'], 'essential' => true],
        'invoice_rejected' => ['channels' => ['database', 'mail'], 'essential' => true],
        'flow_sync_failed' => ['channels' => ['database', 'mail'], 'essential' => true],
        'payment_declared' => ['channels' => ['database'], 'essential' => false],
        'payment_reviewed' => ['channels' => ['database', 'mail'], 'essential' => true],
        'agreement_expiring' => ['channels' => ['database', 'mail'], 'essential' => true],
        'rsi_not_radiated' => ['channels' => ['database', 'mail'], 'essential' => true],
        'fees_unpaid_alert' => ['channels' => ['database', 'mail'], 'essential' => true],
    ],

];
