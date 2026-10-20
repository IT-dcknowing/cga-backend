<?php

namespace App\Domain\Notifications\Contracts;

/**
 * Notification qui peut partir par le canal « sms ».
 */
interface SendsSms
{
    public function toSms(object $notifiable): string;
}
