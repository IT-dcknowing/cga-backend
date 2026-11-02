<?php

use App\Domain\Closing\Support\ClosingReminders;
use Carbon\CarbonImmutable;

/*
 * Dates des rappels de pièces de la campagne de clôture (06-IMPLEMENTATION-PLAN étape 4.10, RG-OBL-11) :
 * J-15 et J-5 avant l'étape 1, puis tous les 7 jours de retard tant que les pièces manquent
 * (paramétrables) ; plus rien une fois les pièces reçues.
 */

function piecesReminderOn(string $day, ?string $received = null, array $offsets = [-15, -5], int $every = 7): ?string
{
    return ClosingReminders::piecesReminder(
        CarbonImmutable::parse('2027-01-30'),
        $received === null ? null : CarbonImmutable::parse($received),
        CarbonImmutable::parse($day),
        $offsets,
        $every,
    );
}

it('RG-OBL-11 · rappelle les pièces à J-15 et J-5 avant l\'étape 1, et pas les autres jours', function () {
    expect(piecesReminderOn('2027-01-15'))->toBe(ClosingReminders::BEFORE)
        ->and(piecesReminderOn('2027-01-25'))->toBe(ClosingReminders::BEFORE)
        ->and(piecesReminderOn('2027-01-14'))->toBeNull()
        ->and(piecesReminderOn('2027-01-24'))->toBeNull()
        ->and(piecesReminderOn('2027-01-30'))->toBeNull();
});

it('RG-OBL-11 · puis tous les 7 jours de retard tant que les pièces manquent', function () {
    expect(piecesReminderOn('2027-02-06'))->toBe(ClosingReminders::LATE)
        ->and(piecesReminderOn('2027-02-13'))->toBe(ClosingReminders::LATE)
        ->and(piecesReminderOn('2027-02-07'))->toBeNull()
        ->and(piecesReminderOn('2027-01-31'))->toBeNull();
});

it('RG-OBL-11 · dates paramétrables ; aucun rappel une fois les pièces reçues', function () {
    expect(piecesReminderOn('2027-01-20', null, [-10], 3))->toBe(ClosingReminders::BEFORE)
        ->and(piecesReminderOn('2027-01-15', null, [-10], 3))->toBeNull()
        ->and(piecesReminderOn('2027-02-02', null, [-10], 3))->toBe(ClosingReminders::LATE)
        ->and(piecesReminderOn('2027-01-15', '2027-01-10'))->toBeNull()
        ->and(piecesReminderOn('2027-02-06', '2027-02-01'))->toBeNull();
});
