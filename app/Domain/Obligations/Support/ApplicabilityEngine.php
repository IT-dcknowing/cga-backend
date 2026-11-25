<?php

namespace App\Domain\Obligations\Support;

use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Members\Models\Member;
use App\Domain\Obligations\Enums\ObligationCategory;
use App\Domain\Obligations\Models\ObligationType;
use Illuminate\Support\Collection;

/**
 * Moteur d'applicabilité des obligations (F-OBL-02, RG-OBL-02, 03) : une obligation s'applique à un
 * dossier quand son régime, son effectif salarié et son secteur remplissent les conditions du
 * catalogue (`applicability` : liste de régimes, effectif minimal, liste de codes de secteur ; une
 * liste vide ne restreint rien). Un effectif salarié nul écarte toujours les obligations CNPS et CMU
 * (RG-OBL-03). Le moteur ne fait que proposer : le CGA confirme, retire ou ajoute.
 */
final class ApplicabilityEngine
{
    public function applies(ObligationType $type, ?TaxRegime $regime, ?int $employees, ?string $sectorCode): bool
    {
        if (! $type->active) {
            return false;
        }

        $conditions = $type->applicability;
        $regimes = (array) ($conditions['regimes'] ?? []);
        $minimum = $conditions['min_employees'] ?? null;
        $sectors = (array) ($conditions['sectors'] ?? []);
        $staff = $employees ?? 0;

        if (in_array($type->category, [ObligationCategory::Cnps, ObligationCategory::Cmu], true) && $staff < 1) {
            return false;
        }

        return ($regimes === [] || ($regime !== null && in_array($regime->value, $regimes, true)))
            && ($minimum === null || $staff >= (int) $minimum)
            && ($sectors === [] || ($sectorCode !== null && in_array($sectorCode, $sectors, true)));
    }

    /**
     * Obligations applicables à un dossier parmi celles proposées (catalogue national en vigueur et
     * obligations locales de son CGA).
     *
     * @param  Collection<int, ObligationType>  $candidates
     * @return Collection<int, ObligationType>
     */
    public function forMember(Member $member, Collection $candidates): Collection
    {
        return $this->filter($candidates, $member->regime, $member->employees_count, $member->sector?->code);
    }

    /**
     * @param  Collection<int, ObligationType>  $candidates
     * @return Collection<int, ObligationType>
     */
    public function filter(Collection $candidates, ?TaxRegime $regime, ?int $employees, ?string $sectorCode): Collection
    {
        return $candidates->filter(fn (ObligationType $type): bool => $this->applies($type, $regime, $employees, $sectorCode))->values();
    }
}
