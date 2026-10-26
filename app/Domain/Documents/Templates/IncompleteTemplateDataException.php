<?php

namespace App\Domain\Documents\Templates;

use LogicException;

/**
 * Des variables du modèle n'ont pas reçu de valeur : le document ne doit pas être produit.
 */
class IncompleteTemplateDataException extends LogicException
{
    /**
     * @param  list<string>  $variables
     */
    public function __construct(public readonly array $variables)
    {
        parent::__construct('Variables sans valeur dans le modèle : '.implode(', ', $variables));
    }
}
