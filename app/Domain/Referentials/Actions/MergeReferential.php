<?php

namespace App\Domain\Referentials\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Referentials\Contracts\Referential;
use App\Domain\Referentials\Enums\SectorStatus;
use App\Domain\Referentials\Models\Sector;
use App\Domain\Referentials\Models\TaxOffice;
use App\Domain\Referentials\Support\ReferentialUsage;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Fusion d'une valeur de référentiel dans une autre (RG-DATA-05) : toutes les données qui désignaient
 * la valeur fusionnée désignent la cible, puis la valeur fusionnée disparaît (un secteur reste,
 * « fusionné », pointant sa cible, RG-REF-01). Un CDI ne se fusionne que dans un CDI de la même DR.
 * Journalisé, avec le nombre de données reprises.
 */
class MergeReferential
{
    public function __construct(
        private readonly ReferentialUsage $usage,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @template TModel of Model&Referential
     *
     * @param  TModel  $source
     * @param  TModel  $target
     *
     * @throws BusinessRuleException merge_same_value, merge_other_directorate, merge_target_inactive
     */
    public function execute(Model&Referential $source, Model&Referential $target): Model
    {
        if ($source->is($target)) {
            throw new BusinessRuleException('merge_same_value', status: 422);
        }
        if ($source instanceof TaxOffice && $target instanceof TaxOffice && $source->regional_directorate_id !== $target->regional_directorate_id) {
            throw new BusinessRuleException('merge_other_directorate', status: 422);
        }
        if ($target->getAttribute('active') === false || ($target instanceof Sector && $target->status !== SectorStatus::Validated)) {
            throw new BusinessRuleException('merge_target_inactive', status: 422);
        }

        return DB::transaction(function () use ($source, $target): Model {
            $source->newQuery()->whereKey([$source->getKey(), $target->getKey()])->lockForUpdate()->get();
            $this->usage->moveTo($source, $target);
            $this->audit->record('merged', $source, context: ['into' => (string) $target->getKey()]);

            if ($source instanceof Sector) {
                $source->status = SectorStatus::Merged;
                $source->merged_into_id = (string) $target->getKey();
                $source->save();
            } else {
                $source->delete();
            }

            return $target->refresh();
        });
    }
}
