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
     * Réserve $count numéros consécutifs (import d'un lot) et rend le premier ; rien n'est réservé pour 0.
     *
     * @throws LogicException hors d'une transaction
     */
    public function reserve(string $organizationId, string $key, int $count): int
    {
        if ($count <= 0) {
            return $this->current($organizationId, $key) + 1;
        }

        $first = $this->next($organizationId, $key);
        if ($count > 1) {
            $this->counter($organizationId, $key, null)->update(['last_value' => $first + $count - 1, 'updated_at' => now()]);
        }

        return $first;
    }

    /**
     * Fait repartir la séquence après $lastValue (numéro de départ choisi à l'import d'un portefeuille,
     * RG-IMP-01) ; jamais en arrière : un numéro attribué ne se réattribue pas.
     *
     * @throws LogicException hors d'une transaction
     */
    public function advanceTo(string $organizationId, string $key, int $lastValue, ?int $year = null): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('SequenceService::advanceTo() doit être appelé dans la transaction de l\'Action.');
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

        $current = (int) $this->counter($organizationId, $key, $year)->lockForUpdate()->value('last_value');
        if ($lastValue > $current) {
            $this->counter($organizationId, $key, $year)->update(['last_value' => $lastValue, 'updated_at' => now()]);
        }
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
