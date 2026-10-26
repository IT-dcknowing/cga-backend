<?php

namespace App\Domain\Members\Http\Requests\Concerns;

use App\Domain\Members\Models\Member;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Dossier de la route /api/v1/cga/members/{member}/…, déjà cherché dans le centre de l'espace actif.
 */
trait RouteMember
{
    /**
     * @throws AuthorizationException route sans dossier
     */
    public function member(): Member
    {
        $member = $this->route('member');

        return $member instanceof Member ? $member : throw new AuthorizationException;
    }
}
