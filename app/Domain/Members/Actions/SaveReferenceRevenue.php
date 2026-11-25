<?php

namespace App\Domain\Members\Actions;

use App\Domain\Members\Enums\RevenueKind;
use App\Domain\Members\Enums\RevenueSource;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\MemberRevenue;
use App\Support\Http\Exceptions\BusinessRuleException;

/**
 * Chiffre d'affaires de référence saisi à l'inscription (F-ADH-02, étape 2) : prévisionnel pour un
 * nouveau contribuable, réalisé N-1 sinon. Une valeur venue de Compta Flow fait foi et ne se
 * remplace pas (RG-DATA-06).
 */
class SaveReferenceRevenue
{
    /**
     * @param  array{year: int, amount: int, kind: string}  $revenue
     *
     * @throws BusinessRuleException source_locked
     */
    public function execute(Member $member, array $revenue): MemberRevenue
    {
        $row = MemberRevenue::query()
            ->where('member_id', $member->id)
            ->where('year', $revenue['year'])
            ->where('kind', $revenue['kind'])
            ->first() ?? new MemberRevenue;

        if ($row->exists && $row->source === RevenueSource::ComptaFlow) {
            throw new BusinessRuleException('source_locked');
        }

        $row->member_id = $member->id;
        $row->organization_id = $member->organization_id;
        $row->year = $revenue['year'];
        $row->kind = RevenueKind::from($revenue['kind']);
        $row->amount = $revenue['amount'];
        $row->source = RevenueSource::Entry;
        $row->save();

        return $row;
    }
}
