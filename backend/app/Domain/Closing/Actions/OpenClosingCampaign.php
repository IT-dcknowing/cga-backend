<?php

namespace App\Domain\Closing\Actions;

use App\Domain\Closing\Enums\ClosingCampaignStatus;
use App\Domain\Closing\Enums\ClosingDeliverable;
use App\Domain\Closing\Models\ClosingCampaign;
use App\Domain\Closing\Models\ClosingCampaignItem;
use App\Domain\Closing\Support\ClosingCalendar;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Members\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Ouverture de la campagne de clôture d'un exercice dans un CGA (F-OBL-08, RG-OBL-10) : calendrier
 * en vigueur figé, un suivi par dossier actif des portefeuilles CGA et Cabinet, livrable proposé selon
 * le régime (TEE, RME : SMT ; RSI, RNI : états financiers ; modifiable, décision 0006). Rejouable :
 * la campagne existe une fois par exercice ; un dossier devenu actif depuis l'ouverture est ajouté,
 * jamais un suivi en double.
 */
class OpenClosingCampaign
{
    public function __construct(private readonly ClosingCalendar $calendar) {}

    /**
     * @return array{campaign: ClosingCampaign, added: int}
     */
    public function execute(string $organizationId, int $fiscalYear, CarbonImmutable $now): array
    {
        return DB::transaction(function () use ($organizationId, $fiscalYear, $now): array {
            $campaign = ClosingCampaign::query()->where('organization_id', $organizationId)->where('fiscal_year', $fiscalYear)->lockForUpdate()->first();
            if ($campaign === null) {
                $campaign = new ClosingCampaign;
                $campaign->organization_id = $organizationId;
                $campaign->fiscal_year = $fiscalYear;
                $campaign->steps = $this->calendar->steps($organizationId, $fiscalYear);
                $campaign->status = ClosingCampaignStatus::Open;
                $campaign->opened_at = $now;
                $campaign->save();
            }

            $added = 0;
            Member::query()
                ->where('organization_id', $organizationId)
                ->whereIn('status', [MemberStatus::Active, MemberStatus::RadiationPending])
                ->whereNotIn('id', ClosingCampaignItem::query()->where('campaign_id', $campaign->id)->select('member_id'))
                ->orderBy('id')
                ->each(static function (Member $member) use ($campaign, &$added): void {
                    $item = new ClosingCampaignItem;
                    $item->organization_id = $campaign->organization_id;
                    $item->campaign_id = $campaign->id;
                    $item->member_id = $member->id;
                    $item->portfolio = $member->portfolio;
                    $item->deliverable = self::deliverable($member->regime);
                    $item->save();
                    $added++;
                });

            return ['campaign' => $campaign, 'added' => $added];
        });
    }

    /** Livrable proposé selon le régime (décision 0006). */
    public static function deliverable(TaxRegime $regime): ClosingDeliverable
    {
        return match ($regime) {
            TaxRegime::Tee, TaxRegime::Rme => ClosingDeliverable::Smt,
            TaxRegime::Rsi, TaxRegime::Rni => ClosingDeliverable::FinancialStatements,
        };
    }
}
