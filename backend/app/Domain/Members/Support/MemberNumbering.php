<?php

namespace App\Domain\Members\Support;

use App\Support\Config\SettingsService;
use App\Support\Numbering\NumberFormatter;

/**
 * Numéros d'un dossier (RG-ADH-03, RG-PTF-01) selon les formats paramétrés : n° CGA Connect du
 * portefeuille CGA ({YYYY} = année d'adhésion), n° client du portefeuille Cabinet. Les séquences
 * « membership » et « client » du centre sont continues, sans remise à zéro annuelle.
 */
class MemberNumbering
{
    public const MEMBERSHIP = 'membership';

    public const CLIENT = 'client';

    public function __construct(private readonly SettingsService $settings) {}

    public function membershipNumber(int $number, ?int $year, ?string $abbreviation): string
    {
        return NumberFormatter::format((string) $this->settings->get('numbering.membership.pattern'), $number, $year, $abbreviation);
    }

    public function clientNumber(int $number, ?string $abbreviation): string
    {
        return NumberFormatter::format((string) $this->settings->get('numbering.client.pattern'), $number, null, $abbreviation);
    }
}
