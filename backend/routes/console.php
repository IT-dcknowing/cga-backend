<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Tâches planifiées (02-TRD §11). Les horaires viennent de config/schedule.php (02-TRD §7.6).
 */

Schedule::command('logs:partitions')
    ->dailyAt(config('schedule.logs_partitions'))
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('obligations:generate')
    ->dailyAt(config('schedule.obligations_generate'))
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

Schedule::command('closing:open-campaign')
    ->cron(config('schedule.closing_open'))
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('dues:open-year')
    ->cron(config('schedule.dues_open_year'))
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('reminders:dues')
    ->dailyAt(config('schedule.reminders_dues'))
    ->withoutOverlapping()
    ->onOneServer();

foreach (['obligations', 'fees', 'documents', 'agreements'] as $reminders) {
    Schedule::command("reminders:{$reminders}")
        ->dailyAt(config("schedule.reminders_{$reminders}"))
        ->withoutOverlapping()
        ->onOneServer();
}

Schedule::command('reminders:reports')
    ->dailyAt(config('schedule.reminders_reports'))
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('reminders:closing')
    ->dailyAt(config('schedule.reminders_closing'))
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('members:radiation-expiry')
    ->dailyAt(config('schedule.radiation_expiry'))
    ->withoutOverlapping()
    ->onOneServer();
