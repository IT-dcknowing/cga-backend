<?php

namespace App\Domain\Statistics\Actions;

use App\Domain\Documents\Actions\IssueDocument;
use App\Domain\Documents\Data\DocumentRequest;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Users\Models\Membership;
use App\Support\Http\Exceptions\BusinessRuleException;

/**
 * « Exporter la plaquette PDF » (F-STA-03, /cncga/statistiques) : plaquette statistique de l'année, émise par la
 * chaîne documentaire du CNCGA (modèle statistics_brochure versionné, PDF produit en file « documents »). Document
 * interne au CNCGA : jamais visible d'un adhérent, pas de version publique en ligne.
 */
final class ProduceStatisticsBrochure
{
    public function __construct(private readonly IssueDocument $issue) {}

    /**
     * @throws BusinessRuleException cga_profile_incomplete (fiche du CNCGA), template_missing
     */
    public function execute(int $year, Membership $requester): GeneratedDocument
    {
        return $this->issue->execute(new DocumentRequest(
            type: DocumentType::StatisticsBrochure,
            title: (string) __('statistics.brochure_title', ['year' => $year]),
            fiscalYear: $year,
            visibleToMember: false,
            sourceType: 'statistics_year',
        ), $requester);
    }
}
