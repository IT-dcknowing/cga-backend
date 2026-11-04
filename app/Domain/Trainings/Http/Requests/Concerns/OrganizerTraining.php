<?php

namespace App\Domain\Trainings\Http\Requests\Concerns;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Trainings\Models\Training;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;

/**
 * Formation de l'organisateur désignée par la route : /network/trainings pour le CNCGA, /cga/trainings pour un
 * CGA. {training} est cherchée dans le centre de l'espace actif (cloisonnement) ; la route d'un autre espace
 * est refusée.
 */
trait OrganizerTraining
{
    protected function organizerSpace(): bool
    {
        $context = app(TenantContext::class);
        if (! $context->has()) {
            return false;
        }

        return $context->organizationType() === ($this->routeIs('network.*') ? OrganizationType::Cncga : OrganizationType::Cga);
    }

    /**
     * @throws ModelNotFoundException
     */
    public function training(): Training
    {
        $training = $this->route('training');

        return $training instanceof Training ? $training : throw (new ModelNotFoundException)->setModel(Training::class);
    }

    protected function managesTraining(): bool
    {
        return $this->organizerSpace() && Gate::allows('manage', $this->training());
    }
}
