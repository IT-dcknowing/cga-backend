<?php

namespace App\Domain\Referentials\Actions;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Referentials\Enums\SectorStatus;
use App\Domain\Referentials\Models\Sector;
use App\Support\Config\SettingsService;
use App\Support\Http\Exceptions\BusinessRuleException;
use App\Support\Numbering\NumberFormatter;
use App\Support\Numbering\SequenceService;
use Illuminate\Support\Facades\DB;

/**
 * « Proposer un secteur » à l'inscription d'un adhérent (RG-REF-01) : le secteur est utilisable tout de
 * suite par le CGA qui le propose, avec le statut « proposé », jusqu'à ce que le CNCGA le valide, le
 * rejette ou le fusionne. Son code est attribué par le serveur, au format numbering.sector_proposal.pattern
 * (abréviation du centre et séquence du centre, sans doublon).
 */
class ProposeSector
{
    private const SEQUENCE = 'sector_proposal';

    /** Longueur de sectors.code (05-BACKEND-SCHEMA §4). */
    private const CODE_MAX = 10;

    public function __construct(
        private readonly SequenceService $sequences,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @throws BusinessRuleException abbreviation_missing : sans abréviation, pas de code ;
     *                               sector_code_too_long : le format paramétré donne un code trop long
     */
    public function execute(string $organizationId, string $name): Sector
    {
        return DB::transaction(function () use ($organizationId, $name): Sector {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organizationId);
            if ($organization->numbering_abbreviation === null) {
                throw new BusinessRuleException('abbreviation_missing');
            }

            $code = NumberFormatter::format(
                (string) $this->settings->get('numbering.sector_proposal.pattern'),
                $this->sequences->next($organizationId, self::SEQUENCE),
                abbreviation: $organization->numbering_abbreviation,
            );
            if (mb_strlen($code) > self::CODE_MAX) {
                throw new BusinessRuleException('sector_code_too_long', details: ['max' => self::CODE_MAX]);
            }

            $sector = new Sector(['code' => $code, 'name' => $name]);
            $sector->status = SectorStatus::Proposed;
            $sector->proposed_by_organization_id = $organizationId;
            $sector->save();

            return $sector->refresh();
        });
    }
}
