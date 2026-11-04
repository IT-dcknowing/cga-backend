<?php

namespace App\Domain\Concerns\Http\Requests\Concerns;

use App\Domain\Concerns\Models\Concern;
use App\Domain\Concerns\Support\ConcernAccess;
use App\Domain\Concerns\Support\ConcernThread;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Préoccupation désignée par la route, cherchée dans le périmètre de l'espace actif : {concern} dans le
 * CGA de l'espace (cloisonnement), {concernId} parmi les demandes des CGA pour le CNCGA. Chaque route sert
 * un seul espace (cga.*, member.*, network.*) ; une demande hors du périmètre de l'utilisateur répond 404
 * comme une demande inexistante (02-TRD §4.3).
 */
trait RouteConcern
{
    private ?Concern $routeConcern = null;

    /**
     * @throws AuthorizationException route d'un autre espace
     * @throws ModelNotFoundException|NotFoundHttpException demande hors du périmètre
     */
    public function concern(): Concern
    {
        if ($this->routeConcern !== null) {
            return $this->routeConcern;
        }

        $access = app(ConcernAccess::class);
        $inSpace = match (true) {
            $this->routeIs('network.*') => $access->inNetwork(),
            $this->routeIs('member.*') => $access->inMemberSpace(),
            default => $access->inCgaAgentSpace(),
        };
        if (! $inSpace) {
            throw new AuthorizationException;
        }

        $bound = $this->route('concern');
        $concern = $bound instanceof Concern ? $bound : ConcernThread::networkConcern((string) $this->route('concernId'));
        if ($access->side($concern) === null) {
            throw (new ModelNotFoundException)->setModel(Concern::class);
        }

        return $this->routeConcern = $concern;
    }
}
