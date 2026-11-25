<?php

namespace App\Domain\Referentials\Actions;

use App\Domain\Referentials\Enums\DataStatus;
use App\Domain\Referentials\ReferenceData\ReferenceDataset;
use App\Domain\Referentials\ReferenceData\ReferenceImportPlanner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * « Marquer comme validé » (RG-DATA-02) : les valeurs provisoires du jeu passent validées, datées.
 * Chaque changement est journalisé.
 */
class ValidateReferenceData
{
    public function __construct(private readonly ReferenceImportPlanner $planner) {}

    /**
     * @return int nombre de valeurs validées
     */
    public function execute(ReferenceDataset $dataset): int
    {
        return DB::transaction(function () use ($dataset): int {
            $provisional = $this->planner->definition($dataset)->query()->where('data_status', DataStatus::Provisional)->lockForUpdate()->get();

            $provisional->each(static function (Model $model): void {
                $model->forceFill(['data_status' => DataStatus::Validated, 'validated_at' => now()])->save();
            });

            return $provisional->count();
        });
    }
}
