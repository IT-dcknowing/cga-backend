<?php

namespace Database\Seeders;

use App\Domain\Referentials\Enums\SectorStatus;
use App\Domain\Referentials\Models\Holiday;
use App\Domain\Referentials\Models\Sector;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use LogicException;

/**
 * Référentiels de départ (06-IMPLEMENTATION-PLAN étape 1.1), provisoires et modifiables par le CNCGA
 * (RG-DATA-01, 02) ; rejouable sans doublon :
 * - les 8 secteurs de départ (F-REF-01) ;
 * - les jours fériés de date connue, pour l'année en cours et la suivante : fêtes à date fixe et fêtes
 *   liées à Pâques. Les fêtes musulmanes, fixées chaque année par annonce officielle, sont saisies par
 *   le CNCGA.
 * Les DR, CDI et communes viennent par import (F-REF-02, étape 1.10).
 */
class ReferentialSeeder extends Seeder
{
    /**
     * @var array<string, string> code => nom (F-REF-01)
     */
    private const SECTORS = [
        'AGR001' => 'Agriculture',
        'COM001' => 'Commerce général',
        'BTP001' => 'BTP',
        'IND001' => 'Industrie / transformation',
        'SER001' => 'Services',
        'SAN001' => 'Santé / pharmacie',
        'TRA001' => 'Transport',
        'NUM001' => 'Numérique',
    ];

    /**
     * @var array<string, string> mois-jour => libellé
     */
    private const FIXED_HOLIDAYS = [
        '01-01' => 'Jour de l\'an',
        '05-01' => 'Fête du travail',
        '08-07' => 'Fête de l\'indépendance',
        '08-15' => 'Assomption',
        '11-01' => 'Toussaint',
        '11-15' => 'Journée nationale de la paix',
        '12-25' => 'Noël',
    ];

    public function run(): void
    {
        foreach (self::SECTORS as $code => $name) {
            if (! Sector::query()->where('code', $code)->exists()) {
                $sector = new Sector(['code' => $code, 'name' => $name]);
                $sector->status = SectorStatus::Validated;
                $sector->validated_at = now();
                $sector->save();
            }
        }

        $year = CarbonImmutable::now()->year;
        foreach ([$year, $year + 1] as $seededYear) {
            foreach ($this->holidaysOf($seededYear) as $date => $label) {
                Holiday::query()->firstOrCreate(['date' => $date], ['label' => $label]);
            }
        }
    }

    /**
     * @return array<string, string> date => libellé
     */
    private function holidaysOf(int $year): array
    {
        $holidays = [];
        foreach (self::FIXED_HOLIDAYS as $monthDay => $label) {
            $holidays["{$year}-{$monthDay}"] = $label;
        }

        $easter = $this->easterSunday($year);
        $holidays[$easter->addDay()->toDateString()] = 'Lundi de Pâques';
        $holidays[$easter->addDays(39)->toDateString()] = 'Ascension';
        $holidays[$easter->addDays(50)->toDateString()] = 'Lundi de Pentecôte';
        ksort($holidays);

        return $holidays;
    }

    /**
     * Dimanche de Pâques du calendrier grégorien (algorithme de Meeus, Jones et Butcher).
     */
    private function easterSunday(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day) ?? throw new LogicException("Date de Pâques invalide pour {$year}.");
    }
}
