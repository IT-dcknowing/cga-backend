<?php

namespace App\Domain\Trainings\Http\Requests\Concerns;

use App\Domain\Trainings\Models\TrainingRegistration;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Inscription désignée par la route ({registration}), cherchée dans le CGA de l'espace actif (cloisonnement).
 */
trait RouteRegistration
{
    /**
     * @throws ModelNotFoundException
     */
    public function registration(): TrainingRegistration
    {
        $registration = $this->route('registration');

        return $registration instanceof TrainingRegistration ? $registration : throw (new ModelNotFoundException)->setModel(TrainingRegistration::class);
    }
}
