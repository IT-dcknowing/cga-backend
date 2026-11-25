<?php

namespace App\Support\Tenancy;

use RuntimeException;

/**
 * Tentative d'écrire une donnée dans une autre organisation que celle de l'espace actif, ou de
 * changer l'organisation d'une donnée (02-TRD §6.3).
 */
class TenancyViolationException extends RuntimeException {}
