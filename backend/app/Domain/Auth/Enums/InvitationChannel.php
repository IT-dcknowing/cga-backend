<?php

namespace App\Domain\Auth\Enums;

use App\Support\Enums\HasLabel;

enum InvitationChannel: string
{
    use HasLabel;

    case Sms = 'sms';
    case Email = 'email';
    case SmsEmail = 'sms_email';
}
