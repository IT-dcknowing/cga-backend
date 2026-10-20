<?php

namespace App\Support\Numbering;

use App\Support\Database\Constraints;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Numérotations sans doublon ni trou (02-TRD §7.1) : la ligne du compteur est verrouillée
 * (SELECT … FOR UPDATE) dans la transaction de l'Action. Si l'Action est annulée, le numéro est
 * rendu avec elle. Année nulle pour une séquence continue (numéro d'adhésion, RG-ADH-03).
 */
class SequenceService
{
    /**
     * @throws LogicException hors d'une transaction : le verrou ne protégerait rien
     */
    public function next(string $organizationId, string $key, ?int $year = null): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('SequenceService::next() doit être appelé dans la transaction de l\'Action.');
        }

        Constraints::identifier($key);

        DB::table('sequences')->insertOrIgnore([
            'organization_id' => $organizationId,
            'key' => $key,
            'year' => $year,
            'last_value' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = $this->counter($organizationId, $key, $year)->lockForUpdate()->first(['last_value']);
        $next = (int) $row->last_value + 1;

        $this->counter($organizationId, $key, $year)->update(['last_value' => $next, 'updated_at' => now()]);

        return $next;
    }

    /**
     * Dernier numéro attribué, 0 si la séquence n'a pas commencé.
     */
    public function current(string $organizationId, string $key, ?int $year = null): int
    {
        return (int) ($this->counter($organizationId, $key, $year)->value('last_value') ?? 0);
    }

    private function counter(string $organizationId, string $key, ?int $year): Builder
    {
        return DB::table('sequences')
            ->where('organization_id', $organizationId)
            ->where('key', $key)
            ->when($year === null, fn (Builder $query) => $query->whereNull('year'), fn (Builder $query) => $query->where('year', $year));
    }
}
