<?php

namespace App\Domain\Members\Events;

use App\Domain\Members\Models\Radiation;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Radiation demandée (F-ADH-06, première étape) : l'adhérent et le gérant en sont avertis (PRD M13).
 * Un abonné n'appelle jamais une Action de radiation (RG-ADH-18).
 */
class RadiationRequested
{
    use Dispatchable;

    public function __construct(public readonly Radiation $radiation) {}
}
