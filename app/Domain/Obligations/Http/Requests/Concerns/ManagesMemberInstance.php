<?php

namespace App\Domain\Obligations\Http\Requests\Concerns;

use App\Domain\Members\Http\Requests\Concerns\RouteMember;
use App\Domain\Obligations\Models\MemberObligation;
use Illuminate\Support\Facades\Gate;

/**
 * Action sur une échéance de la route /api/v1/cga/members/{member}/obligation-instances/{obligationInstance}/…
 * (échéance déjà cherchée parmi celles du dossier) : réservée à qui gère les obligations du dossier.
 */
trait ManagesMemberInstance
{
    use RouteMember;

    public function authorize(): bool
    {
        return Gate::allows('manage', [MemberObligation::class, $this->member()]);
    }
}
