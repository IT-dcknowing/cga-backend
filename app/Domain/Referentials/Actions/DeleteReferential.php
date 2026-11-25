<?php

namespace App\Domain\Referentials\Actions;

use App\Domain\Referentials\Contracts\Referential;
use App\Domain\Referentials\Support\ReferentialUsage;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Supprime une valeur de référentiel qui n'est utilisée nulle part. Utilisée par d'autres données,
 * elle ne se supprime pas (RG-DATA-05) : 409 referential_in_use (désactivation ou fusion à la place).
 */
class DeleteReferential
{
    public function __construct(private readonly ReferentialUsage $usage) {}

    /**
     * @throws BusinessRuleException referential_in_use
     */
    public function execute(Model&Referential $referential): void
    {
        DB::transaction(function () use ($referential): void {
            $referential->newQuery()->whereKey($referential->getKey())->lockForUpdate()->first();

            if ($this->usage->isUsed($referential)) {
                throw new BusinessRuleException('referential_in_use');
            }

            $referential->delete();
        });
    }
}
