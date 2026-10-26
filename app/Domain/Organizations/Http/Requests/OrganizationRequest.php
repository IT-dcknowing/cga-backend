<?php

namespace App\Domain\Organizations\Http\Requests;

use App\Domain\Organizations\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Action du CNCGA sur un CGA du registre : le CGA est le paramètre {organization} de la route.
 */
abstract class OrganizationRequest extends FormRequest
{
    /**
     * @throws AuthorizationException route sans CGA
     */
    public function organization(): Organization
    {
        $organization = $this->route('organization');

        return $organization instanceof Organization ? $organization : throw new AuthorizationException;
    }
}
