<?php

use App\Domain\Referentials\Models\Commune;
use App\Domain\Referentials\Models\Holiday;
use App\Domain\Referentials\Models\RegionalDirectorate;
use App\Domain\Referentials\Models\Sector;
use App\Domain\Referentials\Models\TaxOffice;
use Database\Seeders\ReferentialSeeder;

/*
 * Référentiels de départ (06-IMPLEMENTATION-PLAN étape 1.1) : les 8 secteurs de F-REF-01 et les jours
 * fériés de date connue, provisoires et rejouables sans doublon.
 */

it('F-REF-01 charge les 8 secteurs de départ, validés, en données provisoires', function () {
    $this->seed(ReferentialSeeder::class);
    $this->seed(ReferentialSeeder::class);

    expect(Sector::query()->orderBy('code')->pluck('name', 'code')->all())->toBe([
        'AGR001' => 'Agriculture',
        'BTP001' => 'BTP',
        'COM001' => 'Commerce général',
        'IND001' => 'Industrie / transformation',
        'NUM001' => 'Numérique',
        'SAN001' => 'Santé / pharmacie',
        'SER001' => 'Services',
        'TRA001' => 'Transport',
    ])
        ->and(Sector::query()->where('status', 'validated')->where('data_status', 'provisional')->count())->toBe(8);
});

it('charge les jours fériés à date fixe et ceux liés à Pâques, pour l\'année en cours et la suivante', function () {
    $this->travelTo('2026-03-01');

    $this->seed(ReferentialSeeder::class);
    $this->seed(ReferentialSeeder::class);

    $holidays = Holiday::query()->orderBy('date')->get()->mapWithKeys(fn (Holiday $holiday): array => [$holiday->date->toDateString() => $holiday->label])->all();
    expect($holidays)->toHaveCount(20)
        ->toMatchArray([
            '2026-01-01' => 'Jour de l\'an',
            '2026-04-06' => 'Lundi de Pâques',
            '2026-05-14' => 'Ascension',
            '2026-05-25' => 'Lundi de Pentecôte',
            '2026-08-07' => 'Fête de l\'indépendance',
            '2026-11-15' => 'Journée nationale de la paix',
            '2027-03-29' => 'Lundi de Pâques',
            '2027-05-06' => 'Ascension',
            '2027-05-17' => 'Lundi de Pentecôte',
            '2027-12-25' => 'Noël',
        ]);
});

it('RG-DATA-01 charge des DR, CDI et communes d\'exemple, provisoires, rejouables sans doublon', function () {
    $this->seed(ReferentialSeeder::class);
    $this->seed(ReferentialSeeder::class);

    expect(RegionalDirectorate::query()->count())->toBe(9)
        ->and(RegionalDirectorate::query()->where('data_status', 'provisional')->count())->toBe(9)
        ->and(TaxOffice::query()->where('code', 'CDI-2PLAT')->sole()->regionalDirectorate->code)->toBe('DRAN-4')
        ->and(Commune::query()->count())->toBe(12)
        ->and(Commune::query()->where('name', 'Bouaké')->sole()->regional_directorate_id)->toBe(RegionalDirectorate::query()->where('code', 'DR-BKE')->value('id'));
});
