<?php

namespace App\Domain\Referentials\Support;

use App\Domain\Referentials\Contracts\Referential;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Usages d'une valeur de référentiel par d'autres données (Referential::referencedBy) : une valeur
 * utilisée ne se supprime pas (RG-DATA-05), et ses usages suivent une fusion.
 */
class ReferentialUsage
{
    public function isUsed(Model&Referential $referential): bool
    {
        foreach ($referential::referencedBy() as $table => $column) {
            if ($this->usages($referential, $table, $column)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fait désigner la valeur cible à toutes les données qui désignaient la valeur fusionnée.
     */
    public function moveTo(Model&Referential $source, Model&Referential $target): void
    {
        foreach ($source::referencedBy() as $table => $column) {
            $this->usages($source, $table, $column)->update([$column => $target->getKey()]);
        }
    }

    private function usages(Model&Referential $referential, string $table, string $column): Builder
    {
        return DB::table($table)
            ->where($column, $referential->getKey())
            ->when($table === $referential->getTable(), fn ($query) => $query->where($referential->getKeyName(), '!=', $referential->getKey()));
    }
}
