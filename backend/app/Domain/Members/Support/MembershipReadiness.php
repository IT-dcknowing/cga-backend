<?php

namespace App\Domain\Members\Support;

use App\Domain\Members\Enums\EntityType;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Models\Member;
use App\Support\Config\SettingsService;

/**
 * Ce qui manque pour valider une adhésion (RG-ADH-02) : champs obligatoires de la fiche
 * (F-ADH-02), dirigeant d'une entreprise individuelle, chiffre d'affaires de référence ; au
 * portefeuille CGA, date de signature et confirmation du contrat (RG-PTF-08), droit d'adhésion et,
 * une fois les paiements branchés, son paiement confirmé. Les pièces ne bloquent pas (RG-PJ-02).
 */
class MembershipReadiness
{
    private const REQUIRED = ['tax_office_id', 'sector_id', 'main_activity', 'address', 'phone', 'signatory_name', 'signatory_title'];

    private const OWNER = ['owner_last_name', 'owner_first_names', 'owner_nationality', 'owner_id_document_type', 'owner_id_document_number'];

    public function __construct(private readonly SettingsService $settings) {}

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
            if ((bool) $this->settings->get('members.require_entry_fee_payment')) {
                $missing[] = 'entry_fee_payment';
            }
        }

        return $missing;
    }
}
