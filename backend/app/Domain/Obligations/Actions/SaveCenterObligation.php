<?php

namespace App\Domain\Obligations\Actions;

use App\Domain\Obligations\Data\CenterObligationEntry;
use App\Domain\Obligations\Models\CenterObligation;
use Illuminate\Support\Facades\DB;

/**
 * Obligation propre du CGA (F-OBL-07, « Gestion interne ») : libellé, nature (impôts du CGA, CNPS, CMU,
 * renouvellement d'agrément, SMT…), échéance, montant, statut modifiable, notes.
 */
class SaveCenterObligation
{
    public function execute(string $organizationId, CenterObligationEntry $entry, ?CenterObligation $obligation = null): CenterObligation
    {
        return DB::transaction(function () use ($organizationId, $entry, $obligation): CenterObligation {
            $obligation ??= new CenterObligation;
            $obligation->organization_id = $organizationId;
            $obligation->label = $entry->label;
            $obligation->kind = $entry->kind;
            $obligation->due_date = $entry->dueDate;
            $obligation->amount = $entry->amount;
            $obligation->status = $entry->status;
            $obligation->notes = $entry->notes;
            $obligation->save();

            return $obligation;
        });
    }
}
