<?php

namespace App\Domain\Members\Actions;

use App\Domain\Members\Models\BusinessReferrer;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\PortfolioMovement;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Suppression d'un apporteur saisi par erreur : seulement s'il n'a jamais été rattaché à un dossier
 * (l'historique des bascules garde l'ancien apporteur, RG-PTF-06) ; sinon, il se désactive.
 */
class DeleteReferrer
{
    /**
     * @throws BusinessRuleException referrer_in_use
     */
    public function execute(BusinessReferrer $referrer): void
    {
        DB::transaction(function () use ($referrer): void {
            $used = Member::query()->withTrashed()->where('referrer_id', $referrer->id)->exists()
                || PortfolioMovement::query()->where('previous_referrer_id', $referrer->id)->exists();
            if ($used) {
                throw new BusinessRuleException('referrer_in_use');
            }

            $referrer->delete();
        });
    }
}
