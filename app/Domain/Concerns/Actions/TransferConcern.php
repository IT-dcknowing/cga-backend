<?php

namespace App\Domain\Concerns\Actions;

use App\Domain\Concerns\Enums\ConcernLevel;
use App\Domain\Concerns\Enums\ConcernSide;
use App\Domain\Concerns\Enums\ConcernStatus;
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
 * « Transmettre au CNCGA » une demande d'adhérent que le CGA ne peut pas régler (F-PRE-03), par le gérant
 * ou l'expert-comptable : une demande du CGA au CNCGA, liée, reprend la catégorie et l'objet, la copie de
 * l'échange d'origine puis le message d'accompagnement et ses pièces jointes. La demande d'origine passe
 * « En examen » avec la mention « Transmise au CNCGA » ; l'adhérent est notifié, le CNCGA aussi.
 *
 * Copie de l'échange (décision 0012, RG-SEC-01) : les seuls messages publics, sans leurs pièces jointes,
 * les auteurs désignés par leur côté (« Adhérent », « CGA ») et jamais nommés ; la demande au CNCGA ne porte
 * aucun dossier.
 */
class TransferConcern
{
    public function __construct(
        private readonly SubmitConcern $submit,
        private readonly ConcernThread $thread,
        private readonly ConcernNotifier $notifier,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     *
     * @throws BusinessRuleException concern_already_transferred, concern_closed
     */
    public function execute(Concern $origin, string $message, array $files, Membership $actor): Concern
    {
        return DB::transaction(function () use ($origin, $message, $files, $actor): Concern {
            $origin = Concern::query()->lockForUpdate()->findOrFail($origin->id);
            if ($origin->transferred_to_concern_id !== null) {
                throw new BusinessRuleException('concern_already_transferred');
            }
            if ($origin->status->isClosed()) {
                throw new BusinessRuleException('concern_closed');
            }

            $now = CarbonImmutable::now();
            $network = $this->submit->open(ConcernLevel::CgaToCncga, $origin->category, $origin->subject, $actor, $now, $origin);
            ConcernMessage::query()
                ->where('concern_id', $origin->id)
                ->where('is_internal', false)
                ->where('is_system', false)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->each(fn (ConcernMessage $copied) => $this->thread->post($network, $actor->user_id, ConcernSide::Requester, $copied->body, copiedFrom: $copied, at: $copied->created_at->toImmutable()));
            $this->thread->post($network, $actor->user_id, ConcernSide::Requester, $message, $files, at: $now);

            $origin->transferred_to_concern_id = $network->id;
            $origin->status = ConcernStatus::InReview;
            $origin->first_response_at ??= $now;
            $origin->awaiting_reply_since = null;
            $origin->last_activity_at = $now;
            $origin->save();
            $this->thread->post($origin, $actor->user_id, ConcernSide::Handler, (string) __('concerns.system.transferred'), system: true, at: $now);

            $this->notifier->submitted($network);
            $this->notifier->updated($origin, 'status');

            return $origin;
        });
    }
}
