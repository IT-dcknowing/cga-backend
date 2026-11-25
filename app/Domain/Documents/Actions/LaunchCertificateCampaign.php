<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Jobs\RunCertificateCampaign;
use App\Domain\Documents\Support\CertificateCampaign;
use App\Domain\Users\Models\Membership;
use App\Support\Http\Exceptions\BusinessRuleException;

/**
 * Lancement de la campagne annuelle d'attestations de l'année civile en cours (RG-DOC-06) : la fiche
 * du centre doit être complète (RG-ORG-04) et au moins un dossier éligible ; la production part en
 * tâche de fond (RunCertificateCampaign), l'utilisateur en est informé à la fin.
 */
class LaunchCertificateCampaign
{
    public function __construct(
        private readonly IssueDocument $issue,
        private readonly CertificateCampaign $campaign,
    ) {}

    /**
     * @return int nombre d'attestations à produire
     *
     * @throws BusinessRuleException cga_profile_incomplete, certificate_campaign_empty
     */
    public function execute(Membership $requester): int
    {
        $this->issue->ensureProfileComplete($requester->organization_id);

        $year = today()->year;
        $eligible = $this->campaign->eligible($year)->count();
        if ($eligible === 0) {
            throw new BusinessRuleException('certificate_campaign_empty', details: ['year' => $year]);
        }

        RunCertificateCampaign::dispatch($requester->id, $year);

        return $eligible;
    }
}
