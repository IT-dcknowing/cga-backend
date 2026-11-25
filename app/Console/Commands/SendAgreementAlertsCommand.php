<?php

namespace App\Console\Commands;

/**
 * `reminders:agreements` (02-TRD §11, PRD M13).
 */
class SendAgreementAlertsCommand extends SendNotificationReminders
{
    protected $signature = 'reminders:agreements';

    protected $description = "Alertes d'agrément, de régime RSI / RNI et d'impayés anciens";

    protected function kind(): string
    {
        return 'agreements';
    }
}
