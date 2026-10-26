<?php

namespace App\Domain\Users\Permissions;

use InvalidArgumentException;

/**
 * Code de permission absent du catalogue : erreur du code appelant, jamais une donnée utilisateur.
 */
class UnknownPermissionException extends InvalidArgumentException
{
    public function __construct(string $code)
    {
        parent::__construct("Permission absente du catalogue config/permissions.php : {$code}.");
    }
}
