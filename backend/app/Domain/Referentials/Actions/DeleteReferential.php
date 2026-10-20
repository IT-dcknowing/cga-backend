<?php

namespace App\Domain\Referentials\Actions;

use App\Domain\Referentials\Contracts\Referential;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Supprime une valeur de référentiel qui n'est utilisée nulle part. Utilisée par d'autres données,
 * elle ne se supprime pas (RG-DATA-05) : 409 referential_in_use (désactivation ou fusion à la place).
 */
class DeleteReferential
{
    /**
     * @throws BusinessRuleException referential_in_use
     */
    public function execute(Model&Referential $referential): void
    {
        DB::transaction(static function () use ($referential): void {
            $referential->newQuery()->whereKey($referential->getKey())->lockForUpdate()->first();

            foreach ($referential::referencedBy() as $table => $column) {
                $used = DB::table($table)
                    ->where($column, $referential->getKey())
                    ->when($table === $referential->getTable(), fn ($query) => $query->where($referential->getKeyName(), '!=', $referential->getKey()))
                    ->exists();

                if ($used) {
                    throw new BusinessRuleException('referential_in_use');
                }
            }

            $referential->delete();
        });
    }
}
