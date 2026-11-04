<?php

namespace App\Domain\Concerns\Http\Controllers;

use App\Domain\Concerns\Enums\ConcernSide;
use App\Domain\Concerns\Http\Resources\ConcernResource;
use App\Domain\Concerns\Models\Concern;
use App\Domain\Concerns\Support\ConcernAccess;
use App\Domain\Concerns\Support\ConcernThread;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Gate;

/**
 * Préoccupation rendue à un espace, avec son fil : le demandeur ne reçoit jamais les notes internes (elles
 * ne sont pas lues), qui traite la demande les reçoit signalées. Actions permises calculées par la Policy.
 */
trait PresentsConcerns
{
    protected function present(Concern $concern, string $space, bool $withThread = true): ConcernResource
    {
        $side = app(ConcernAccess::class)->side($concern) ?? ConcernSide::Requester;

        ConcernThread::within($concern, static function () use ($concern, $space, $side, $withThread): void {
            $relations = match ($space) {
                ConcernResource::SPACE_CGA => ['author', 'member', 'transferredTo'],
                ConcernResource::SPACE_NETWORK => ['author', 'organization'],
                default => ['author'],
            };
            if ($withThread) {
                $relations['messages'] = static fn (HasMany $messages) => $side === ConcernSide::Requester ? $messages->where('is_internal', false) : $messages;
                $relations[] = 'messages.author';
                $relations[] = 'messages.attachments.file';
            }
            $concern->load($relations);
        });

        $handles = Gate::allows('handle', $concern);
        $open = ! $concern->status->isClosed();

        return (new ConcernResource($concern))->viewedFrom($space, $side)->withAbilities([
            'reply' => $open && Gate::allows('reply', $concern),
            'note' => $open && $handles,
            'change_status' => $handles && $concern->status->handlerTargets() !== [],
            'reopen' => ! $open && Gate::allows('reopen', $concern),
            'transfer' => $open && $concern->transferred_to_concern_id === null && Gate::allows('transfer', $concern),
        ]);
    }
}
