<?php

namespace App\Domain\Reports\Support;

use App\Domain\Closing\Models\ClosingCampaign;
use App\Domain\Closing\Support\ClosingProgress;

/**
 * Nombres de SMT et de bilans établis et transmis proposés pour la section 4 du rapport (RG-OBL-12) :
 * campagne de clôture de l'exercice précédant l'année du rapport, portefeuille CGA seulement ;
 * modifiables avant génération. Aucune campagne : aucune proposition.
 */
final class ClosingProposals
{
    /**
     * @return array{smt_established: int, smt_transmitted: int, balance_sheets_established: int, balance_sheets_transmitted: int, fiscal_year: int}|null
     */
    public function forReport(string $organizationId, int $year): ?array
    {
        $campaign = ClosingCampaign::query()->where('organization_id', $organizationId)->where('fiscal_year', $year - 1)->first();
        if ($campaign === null) {
            return null;
        }
        $counts = ClosingProgress::report($campaign);

        return [
            'smt_established' => $counts['smt_established'],
            'smt_transmitted' => $counts['smt_transmitted'],
            'balance_sheets_established' => $counts['financial_statements_established'],
            'balance_sheets_transmitted' => $counts['financial_statements_transmitted'],
            'fiscal_year' => $campaign->fiscal_year,
        ];
    }
}
