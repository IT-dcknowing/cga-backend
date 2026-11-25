<?php

use App\Domain\Referentials\Models\Holiday;
use App\Support\Calendar\BusinessCalendar;
use Carbon\CarbonImmutable;

/*
 * Jours ouvrés et report d'une échéance au jour ouvré suivant (02-TRD §7.3, RG-OBL-06).
 */

function holiday(string $date, string $label): void
{
    Holiday::query()->create(['date' => $date, 'label' => $label]);
}

function nextBusinessDay(string $date): string
{
    return app(BusinessCalendar::class)->nextBusinessDay(CarbonImmutable::parse($date))->toDateString();
}

it('RG-OBL-06 garde une échéance qui tombe un jour ouvré', function () {
    expect(nextBusinessDay('2026-10-15'))->toBe('2026-10-15');
});

it('RG-OBL-06 reporte une échéance du samedi ou du dimanche au lundi', function (string $weekend) {
    expect(nextBusinessDay($weekend))->toBe('2026-10-19');
})->with(['2026-10-17', '2026-10-18']);

it('RG-OBL-06 reporte une échéance qui tombe un jour férié', function () {
    holiday('2026-08-07', 'Fête de l\'Indépendance');

    expect(nextBusinessDay('2026-08-07'))->toBe('2026-08-10');
});

it('RG-OBL-06 enchaîne les jours fériés et le week-end', function () {
    holiday('2026-12-24', 'Veille de Noël (essai)');
    holiday('2026-12-25', 'Noël');

    expect(nextBusinessDay('2026-12-24'))->toBe('2026-12-28');
});

it('tient compte d\'un jour férié ajouté après une première lecture du calendrier', function () {
    $calendar = app(BusinessCalendar::class);
    expect($calendar->isBusinessDay(CarbonImmutable::parse('2026-11-02')))->toBeTrue();

    holiday('2026-11-02', 'Jour férié ajouté');

    expect($calendar->isBusinessDay(CarbonImmutable::parse('2026-11-02')))->toBeFalse();
});

it('renvoie le jour en début de journée, sans l\'heure', function () {
    expect(app(BusinessCalendar::class)->nextBusinessDay(CarbonImmutable::parse('2026-10-17 15:30'))->format('Y-m-d H:i'))->toBe('2026-10-19 00:00');
});
