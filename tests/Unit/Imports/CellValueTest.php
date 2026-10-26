<?php

use App\Domain\Imports\Support\CellValue;

/*
 * Valeurs des cellules d'un fichier importé (M17) : texte et dates, quel que soit le type de cellule.
 */

it('lit le texte d\'une cellule, nombres compris', function (mixed $cell, ?string $text) {
    expect(CellValue::text($cell))->toBe($text);
})->with([
    'texte' => ['CGA', 'CGA'],
    'vide' => ['', null],
    'absent' => [null, null],
    'entier' => [707070707, '707070707'],
    'décimal entier' => [2020.0, '2020'],
    'décimal' => [12.5, '12.5'],
    'booléen' => [true, '1'],
    'date' => [new DateTimeImmutable('2019-03-15 10:00'), '2019-03-15'],
    'tableau' => [[1], null],
]);

it('lit une date en cellule date, en numéro de série Excel ou en texte', function (mixed $cell, string|false|null $date) {
    $value = CellValue::date($cell);

    expect($value === false || $value === null ? $value : $value->toDateString())->toBe($date);
})->with([
    'cellule date' => [new DateTimeImmutable('2019-03-15 10:30'), '2019-03-15'],
    'numéro de série' => [43539, '2019-03-15'],
    'numéro de série décimal' => [43539.75, '2019-03-15'],
    'jj/mm/aaaa' => ['15/03/2019', '2019-03-15'],
    'aaaa-mm-jj' => ['2019-03-15', '2019-03-15'],
    'jj-mm-aaaa' => ['15-03-2019', '2019-03-15'],
    'jj.mm.aaaa' => ['15.03.2019', '2019-03-15'],
    'jour inexistant' => ['31/02/2019', false],
    'texte' => ['en cours', false],
    'numéro hors limites' => [-5, false],
    'booléen' => [true, false],
    'vide' => ['', null],
    'absente' => [null, null],
]);
