<?php

namespace App\Domain\Members\Events;

use App\Domain\Members\Models\Radiation;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Radiation validée (F-ADH-06), émise dans la transaction de la validation : les effets portés par
 * d'autres modules s'y abonnent — courrier au CDI (effet 3, Lot 3), échéances futures annulées
 * (effet 4 : honoraires au Lot 3, obligations au Lot 4), notification à l'adhérent (effet 6, Lot 6).
 * Un abonné n'appelle jamais une Action de radiation (RG-ADH-18).
 */
class RadiationValidated
{
    use Dispatchable;

    public function __construct(public readonly Radiation $radiation) {}
}
