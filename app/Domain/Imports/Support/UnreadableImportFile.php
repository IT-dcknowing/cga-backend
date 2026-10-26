<?php

namespace App\Domain\Imports\Support;

use RuntimeException;

/**
 * Fichier déposé qui ne peut pas être lu comme le modèle (illisible, vide, colonnes obligatoires
 * absentes) : son message, en français, va dans le rapport d'import.
 */
class UnreadableImportFile extends RuntimeException {}
