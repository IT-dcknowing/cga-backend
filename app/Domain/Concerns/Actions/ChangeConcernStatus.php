<?php

namespace App\Domain\Concerns\Actions;

use App\Domain\Concerns\Enums\ConcernSide;
use App\Domain\Concerns\Enums\ConcernStatus;
use App\Domain\Concerns\Models\Concern;
use App\Domain\Concerns\Support\ConcernNotifier;
use App\Domain\Concerns\Support\ConcernThread;
use App\Domain\Users\Models\Membership;
use App\Support\Http\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Changer le statut d'une préoccupation (F-PRE-02), par qui la traite : Ouverte → En examen → Traitée ou
 * Rejetée (motif obligatoire). Traitée ou rejetée, la demande n'attend plus de réponse. Le changement est
 * inscrit au fil et le demandeur est notifié.
 */
class ChangeConcernStatus
{
    public function __construct(
        private readonly ConcernThread $thread,
        private readonly ConcernNotifier $notifier,
    ) {}

    /**
     * @throws BusinessRuleException concern_status_invalid
     */
    public function execute(Concern $concern, ConcernStatus $target, ?string $reason, Membership $actor): Concern
    {
        return ConcernThread::within($concern, fn (): Concern => DB::transaction(function () use ($concern, $target, $reason, $actor): Concern {
            $concern = Concern::query()->lockForUpdate()->findOrFail($concern->id);
            if (! in_array($target, $concern->status->handlerTargets(), true)) {
                throw new BusinessRuleException('concern_status_invalid');
            }

            $now = CarbonImmutable::now();
            $concern->status = $target;
            $concern->rejection_reason = $target === ConcernStatus::Rejected ? $reason : null;
            $concern->last_activity_at = $now;
            if ($target->isClosed()) {
                $concern->resolved_at = $now;
                $concern->first_response_at ??= $now;
                $concern->awaiting_reply_since = null;
            }
            $concern->save();

            $body = $target === ConcernStatus::Rejected
                ? (string) __('concerns.system.rejected', ['reason' => (string) $reason])
                : (string) __('concerns.system.status', ['status' => $target->label()]);
            $this->thread->post($concern, $actor->user_id, ConcernSide::Handler, $body, system: true, at: $now);
            $this->notifier->updated($concern, 'status');

            return $concern;
        }));
    }
}
