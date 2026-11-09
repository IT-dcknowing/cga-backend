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
 * « Rouvrir la demande » (F-PRE-02) : le demandeur ramène une demande traitée ou rejetée à « Ouverte » ;
 * elle attend de nouveau une réponse, le motif de rejet est retiré (il reste au fil), qui la traite est
 * notifié.
 */
class ReopenConcern
{
    public function __construct(
        private readonly ConcernThread $thread,
        private readonly ConcernNotifier $notifier,
    ) {}

    /**
     * @throws BusinessRuleException concern_not_closed
     */
    public function execute(Concern $concern, Membership $actor): Concern
    {
        return ConcernThread::within($concern, fn (): Concern => DB::transaction(function () use ($concern, $actor): Concern {
            $concern = Concern::query()->lockForUpdate()->findOrFail($concern->id);
            if (! $concern->status->isClosed()) {
                throw new BusinessRuleException('concern_not_closed');
            }

            $now = CarbonImmutable::now();
            $concern->status = ConcernStatus::Open;
            $concern->rejection_reason = null;
            $concern->resolved_at = null;
            $concern->reopen_count++;
            $concern->awaiting_reply_since = $now;
            $concern->last_activity_at = $now;
            $concern->save();

            $this->thread->post($concern, $actor->user_id, ConcernSide::Requester, (string) __('concerns.system.reopened'), system: true, at: $now);
            $this->notifier->followUp($concern, 'reopened');

            return $concern;
        }));
    }
}
