<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Tâches planifiées (02-TRD §11). Les horaires viennent de config/schedule.php (02-TRD §7.6).
 */

Schedule::command('logs:partitions')
    ->dailyAt(config('schedule.logs_partitions'))
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('fees:generate')
    ->dailyAt(config('schedule.fees_generate'))
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('statuses:refresh')
    ->dailyAt(config('schedule.statuses_refresh'))
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('fne:retry')
    ->cron(config('schedule.fne_retry'))
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('members:radiation-expiry')
    ->dailyAt(config('schedule.radiation_expiry'))
    ->withoutOverlapping()
    ->onOneServer();
