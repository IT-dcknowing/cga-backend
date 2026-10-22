<?php

namespace App\Domain\Documents\Events;

use App\Domain\Documents\Models\GeneratedDocument;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Document produit et valide (02-TRD §8.2, RG-DOC-05) : celui qui l'a demandé est prévenu et, si le
 * document lui est visible, l'adhérent (notifications, Lot 6). Émis une fois la transaction validée.
 */
class DocumentReady implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly GeneratedDocument $document) {}
}
