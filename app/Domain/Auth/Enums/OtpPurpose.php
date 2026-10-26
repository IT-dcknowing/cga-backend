<?php

namespace App\Domain\Auth\Enums;

use App\Support\Enums\HasLabel;

enum OtpPurpose: string
{
    use HasLabel;

    case PasswordReset = 'password_reset';
    case PhoneChange = 'phone_change';
}
