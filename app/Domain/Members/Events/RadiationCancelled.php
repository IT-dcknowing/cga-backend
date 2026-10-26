<?php

namespace App\Domain\Members\Events;

use App\Domain\Members\Models\Radiation;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Radiation annulée (F-ADH-07, RG-ADH-11), émise dans la transaction de l'annulation : les modules
 * qui ont réagi à sa validation y restaurent leur part (échéances, rapport du trimestre).
 * $wasValidated : la radiation avait été validée (sinon, seulement demandée).
 */
class RadiationCancelled
{
    use Dispatchable;

    public function __construct(public readonly Radiation $radiation, public readonly bool $wasValidated) {}
}
