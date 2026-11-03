<?php

namespace App\Console\Commands;

/**
 * `reminders:documents` (02-TRD §11, PRD M13).
 */
class SendDocumentRemindersCommand extends SendNotificationReminders
{
    protected $signature = 'reminders:documents';

    protected $description = 'Rappels des pièces en attente';

    protected function kind(): string
    {
        return 'documents';
    }
}
