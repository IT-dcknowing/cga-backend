<?php

namespace App\Domain\Obligations\Actions;

use App\Domain\Obligations\Models\ObligationType;
use App\Domain\Referentials\Enums\DataStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * « Marquer comme validé » une version du catalogue national (RG-DATA-02) : ses obligations
 * provisoires passent « validées » par le CNCGA.
 */
class ValidateObligationCatalogVersion
{
    /**
     * @return int obligations validées
     *
     * @throws ModelNotFoundException version inconnue
     */
    public function execute(string $version, CarbonImmutable $now): int
    {
        return DB::transaction(static function () use ($version, $now): int {
            $types = ObligationType::query()->whereNull('organization_id')->where('catalog_version', $version)->lockForUpdate()->get();
            if ($types->isEmpty()) {
                throw (new ModelNotFoundException)->setModel(ObligationType::class);
            }

            $validated = 0;
            foreach ($types->where('data_status', DataStatus::Provisional) as $type) {
                $type->data_status = DataStatus::Validated;
                $type->validated_at = $now;
                $type->save();
                $validated++;
            }

            return $validated;
        });
    }
}
