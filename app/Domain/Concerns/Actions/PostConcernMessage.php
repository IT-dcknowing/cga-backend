<?php

namespace App\Domain\Concerns\Actions;

use App\Domain\Concerns\Enums\ConcernSide;
use App\Domain\Concerns\Models\Concern;
use App\Domain\Concerns\Models\ConcernMessage;
use App\Domain\Concerns\Support\ConcernNotifier;
use App\Domain\Concerns\Support\ConcernThread;
use App\Domain\Users\Models\Membership;
use App\Support\Http\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Écrire dans le fil d'une préoccupation (F-PRE-02) : réponse de qui la traite (le demandeur est notifié,
 * la demande n'attend plus de réponse), note interne invisible du demandeur (sans notification), ou message
 * du demandeur (qui la traite est notifié, la demande attend une réponse). Rien sur une demande traitée ou
 * rejetée : le demandeur la rouvre d'abord.
 */
class PostConcernMessage
{
    public function __construct(
        private readonly ConcernThread $thread,
        private readonly ConcernNotifier $notifier,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     *
     * @throws BusinessRuleException concern_closed
     */
    public function execute(Concern $concern, ConcernSide $side, string $body, array $files, bool $internal, Membership $actor): ConcernMessage
    {
        return ConcernThread::within($concern, fn (): ConcernMessage => DB::transaction(function () use ($concern, $side, $body, $files, $internal, $actor): ConcernMessage {
            $concern = Concern::query()->lockForUpdate()->findOrFail($concern->id);
            if ($concern->status->isClosed()) {
                throw new BusinessRuleException('concern_closed');
            }

            $internal = $internal && $side === ConcernSide::Handler;
            $now = CarbonImmutable::now();
            $message = $this->thread->post($concern, $actor->user_id, $side, $body, $files, internal: $internal, at: $now);
            if ($internal) {
                return $message;
            }

            $concern->last_activity_at = $now;
            if ($side === ConcernSide::Handler) {
                $concern->first_response_at ??= $now;
                $concern->awaiting_reply_since = null;
                $concern->save();
                $this->notifier->updated($concern, 'reply');
            } else {
                $concern->awaiting_reply_since ??= $now;
                $concern->save();
                $this->notifier->followUp($concern, 'reply');
            }

            return $message;
        }));
    }
}
