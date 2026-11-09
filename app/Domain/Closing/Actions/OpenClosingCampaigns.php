<?php

namespace App\Domain\Closing\Actions;

use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Models\Organization;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;

/**
 * `closing:open-campaign` (02-TRD §11) : ouvre la campagne de clôture de l'exercice dans chaque CGA
 * non résilié, avec le calendrier en vigueur pour ce centre (RG-OBL-10). Rejouable.
 */
class OpenClosingCampaigns
{
    public function __construct(private readonly OpenClosingCampaign $open) {}

    /**
     * @return array{campaigns: int, items: int}
     */
    public function execute(int $fiscalYear, CarbonImmutable $now): array
    {
        return Tenancy::bypass(function () use ($fiscalYear, $now): array {
            $campaigns = $items = 0;
            Organization::query()
                ->where('type', OrganizationType::Cga)
                ->where('status', '<>', OrganizationStatus::Terminated)
                ->orderBy('id')
                ->each(function (Organization $cga) use ($fiscalYear, $now, &$campaigns, &$items): void {
                    $items += $this->open->execute($cga->id, $fiscalYear, $now)['added'];
                    $campaigns++;
                });

            return ['campaigns' => $campaigns, 'items' => $items];
        }, 'Tâche closing:open-campaign : campagne de clôture de l\'exercice dans chaque CGA (RG-OBL-10).');
    }
}
