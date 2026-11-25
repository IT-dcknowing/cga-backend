<?php

namespace App\Domain\Concerns\Enums;

use App\Support\Enums\HasLabel;

/**
 * Côté de l'auteur d'un message : le demandeur (l'adhérent, ou le CGA qui écrit au CNCGA) ou celui
 * qui traite la demande (les agents du CGA, ou le CNCGA).
 */
enum ConcernSide: string
{
    use HasLabel;

    case Requester = 'requester';
    case Handler = 'handler';
}
