<?php

namespace App\Domain\Reports\Actions;

use App\Domain\Reports\Data\CgaPersonEntry;
use App\Domain\Reports\Models\CgaPerson;
use Illuminate\Support\Facades\DB;

/**
 * Dirigeant, salarié permanent ou occasionnel de la fiche permanente (F-RPT-01) : ajout ou mise à
 * jour. Une personne qui quitte le centre est désactivée avec sa date de fin : elle sort des rapports
 * suivants et reste dans les versions déjà générées (RG-RPT-05).
 */
class SaveCgaPerson
{
    public function execute(string $organizationId, CgaPersonEntry $entry, ?CgaPerson $person = null): CgaPerson
    {
        return DB::transaction(function () use ($organizationId, $entry, $person): CgaPerson {
            $person ??= new CgaPerson;
            $person->organization_id = $organizationId;
            $person->kind = $entry->kind;
            $person->full_name = $entry->fullName;
            $person->nationality = $entry->nationality;
            $person->profession = $entry->profession;
            $person->diplomas = $entry->diplomas;
            $person->function_in_cga = $entry->functionInCga;
            $person->start_date = $entry->startDate;
            $person->practice_structure = $entry->practiceStructure;
            $person->practice_function = $entry->practiceFunction;
            $person->practice_years = $entry->practiceYears;
            $person->cnps_declared = $entry->cnpsDeclared;
            $person->active = $entry->active;
            $person->end_date = $entry->endDate;
            $person->save();

            return $person;
        });
    }
}
