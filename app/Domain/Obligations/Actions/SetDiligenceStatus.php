<?php

namespace App\Domain\Obligations\Actions;

use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Support\Http\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Avancement d'une diligence (F-OBL-04) : non établie, en cours, établie ; la date d'établissement est
 * notée quand elle passe « établie ».
 */
class SetDiligenceStatus
{
    /**
     * @throws BusinessRuleException instance_not_diligence, instance_cancelled
     */
    public function execute(ObligationInstance $instance, InstanceStatus $status, CarbonImmutable $today): ObligationInstance
    {
        return DB::transaction(function () use ($instance, $status, $today): ObligationInstance {
            $instance = ObligationInstance::query()->lockForUpdate()->findOrFail($instance->id);
            if ($instance->kind !== InstanceKind::Diligence) {
                throw new BusinessRuleException('instance_not_diligence');
            }
            if ($instance->status === InstanceStatus::Cancelled) {
                throw new BusinessRuleException('instance_cancelled');
            }

            $instance->status = $status;
            $instance->done_at = $status === InstanceStatus::Established ? ($instance->done_at ?? $today) : null;
            $instance->save();

            return $instance;
        });
    }
}
