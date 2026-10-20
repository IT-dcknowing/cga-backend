<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Tâches planifiées (02-TRD §11). Les horaires viennent de config/schedule.php (02-TRD §7.6).
 */

Schedule::command('logs:partitions')
    ->dailyAt(config('schedule.logs_partitions'))
    ->withoutOverlapping()
    ->onOneServer();
