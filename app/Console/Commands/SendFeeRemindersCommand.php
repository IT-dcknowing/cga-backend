<?php

namespace App\Console\Commands;

/**
 * `reminders:fees` (02-TRD §11, PRD M13).
 */
class SendFeeRemindersCommand extends SendNotificationReminders
{
    protected $signature = 'reminders:fees';

    protected $description = 'Rappels des honoraires impayés';

    protected function kind(): string
    {
        return 'fees';
    }
}
