<?php

namespace App\Domain\Audit\Enums;

use App\Support\Enums\HasLabel;

/**
 * Résultat d'une action dans le journal d'activité (02-TRD §6.5).
 */
enum ActivityResult: string
{
    use HasLabel;

    case Success = 'success';
    case ValidationFailed = 'validation_failed';
    case Denied = 'denied';
    case NotFound = 'not_found';
    case BusinessError = 'business_error';
    case Throttled = 'throttled';
    case Error = 'error';

    /**
     * Résultat déduit du code HTTP quand aucune erreur n'a été traduite par ApiErrorMapper.
     */
    public static function fromStatus(int $status): self
    {
        return match (true) {
            $status < 400 => self::Success,
            in_array($status, [401, 403, 419], true) => self::Denied,
            $status === 404 => self::NotFound,
            $status === 409 => self::BusinessError,
            $status === 422 => self::ValidationFailed,
            $status === 429 => self::Throttled,
            default => self::Error,
        };
    }
}
