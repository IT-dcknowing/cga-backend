<?php

namespace App\Support\Tenancy;

use RuntimeException;

/**
 * Lecture ou écriture d'une donnée cloisonnée sans espace actif ni contournement déclaré : refusée
 * (fermé par défaut, 02-TRD §6.3 et §6.7 A10).
 */
class MissingTenantContextException extends RuntimeException
{
    public function __construct(string $message = 'Aucun espace actif : accès aux données cloisonnées refusé.')
    {
        parent::__construct($message);
    }
}
