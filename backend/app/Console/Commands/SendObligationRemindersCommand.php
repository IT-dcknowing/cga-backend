<?php

namespace App\Console\Commands;

/**
 * `reminders:obligations` (02-TRD §11, PRD M13).
 */
class SendObligationRemindersCommand extends SendNotificationReminders
{
    protected $signature = 'reminders:obligations';

    protected $description = "Rappels d'échéances fiscales et sociales (J-7, J-1, dépassées)";

    protected function kind(): string
    {
        return 'obligations';
    }
}
