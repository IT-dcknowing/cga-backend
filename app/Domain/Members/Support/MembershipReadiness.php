<?php

namespace App\Domain\Members\Support;

use App\Domain\Members\Enums\EntityType;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Models\Member;
use App\Domain\Payments\Enums\ChargeKind;
use App\Domain\Payments\Enums\ChargeStatus;
use App\Domain\Payments\Models\MemberCharge;

/**
 * Ce qui manque pour valider une adhésion (RG-ADH-02) : champs obligatoires de la fiche
 * (F-ADH-02), dirigeant d'une entreprise individuelle, chiffre d'affaires de référence ; au
 * portefeuille CGA, date de signature et confirmation du contrat (RG-PTF-08), droit d'adhésion
 * entièrement payé, paiements confirmés (F-PAY-02). Les pièces ne bloquent pas (RG-PJ-02).
 */
class MembershipReadiness
{
    private const REQUIRED = ['tax_office_id', 'sector_id', 'main_activity', 'address', 'phone', 'signatory_name', 'signatory_title'];

    private const OWNER = ['owner_last_name', 'owner_first_names', 'owner_nationality', 'owner_id_document_type', 'owner_id_document_number'];

    /**
     * @return list<string> champs ou conditions manquants
     */
    public function missing(Member $member): array
    {
        $missing = array_values(array_filter(self::REQUIRED, static fn (string $field): bool => blank($member->getAttribute($field))));

        if ($member->entity_type === EntityType::Individual) {
            $missing = [...$missing, ...array_values(array_filter(self::OWNER, static fn (string $field): bool => blank($member->getAttribute($field))))];
        }
        if (! $member->revenues()->exists()) {
            $missing[] = 'reference_revenue';
        }
        if ($member->portfolio === Portfolio::Cga) {
            if ($member->cga_contract_signed_on === null) {
                $missing[] = 'cga_contract_signed_on';
            }
            if ($member->cga_contract_confirmed_by === null) {
                $missing[] = 'cga_contract_confirmed';
            }
            if ($this->entryFeeDue($member)) {
                $missing[] = 'entry_fee_payment';
            }
        }

        return $missing;
    }

    /**
     * Le droit d'adhésion du dossier n'est pas encore entièrement payé (RG-ADH-02) ; un droit à zéro
     * n'ouvre aucune somme due.
     */
    private function entryFeeDue(Member $member): bool
    {
        return MemberCharge::query()
            ->where('member_id', $member->id)
            ->where('kind', ChargeKind::EntryFee)
            ->whereIn('status', [ChargeStatus::Due, ChargeStatus::Partial, ChargeStatus::Overdue])
            ->exists();
    }
}
