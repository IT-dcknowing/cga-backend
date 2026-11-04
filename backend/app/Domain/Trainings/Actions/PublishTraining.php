<?php

namespace App\Domain\Trainings\Actions;

use App\Domain\Trainings\Enums\TrainingStatus;
use App\Domain\Trainings\Models\Training;
use App\Domain\Trainings\Support\TrainingNotifier;
use App\Support\Http\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * « Publier » une formation en brouillon (RG-FOR-01 : publication directe, sans validation croisée) : les
 * inscriptions s'ouvrent jusqu'au début de la session ; le public ciblé est notifié et, pour une formation
 * du CNCGA, les CGA concernés (RG-FOR-02).
 */
class PublishTraining
{
    public function __construct(private readonly TrainingNotifier $notifier) {}

    /**
     * @return int personnes notifiées
     *
     * @throws BusinessRuleException training_not_draft, training_already_started
     */
    public function execute(Training $training): int
    {
        return DB::transaction(function () use ($training): int {
            $training = Training::query()->with('organization')->lockForUpdate()->findOrFail($training->id);
            if ($training->status !== TrainingStatus::Draft) {
                throw new BusinessRuleException('training_not_draft');
            }
            $now = CarbonImmutable::now();
            if ($training->starts_at->lessThanOrEqualTo($now)) {
                throw new BusinessRuleException('training_already_started');
            }

            $training->status = TrainingStatus::Published;
            $training->published_at = $now;
            $training->save();

            return $this->notifier->published($training);
        });
    }
}
