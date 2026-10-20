<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Data\IssuedCode;
use App\Domain\Auth\Enums\OtpPurpose;
use App\Domain\Auth\Models\OtpCode;
use App\Domain\Auth\Notifications\OneTimeCodeNotification;
use App\Domain\Users\Models\User;
use App\Support\Config\SettingsService;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Crée et envoie un code à usage unique (02-TRD §6.1) : chiffres tirés par le générateur
 * cryptographique, seule leur empreinte est gardée, validité otp.validity_minutes. Un nouveau code
 * annule les précédents de même objet.
 */
class IssueOneTimeCode
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly Repository $config,
    ) {}

    /**
     * @param  string  $target  téléphone E.164 ou e-mail qui reçoit le code
     * @param  string  $channel  canal de notification : sms ou mail
     */
    public function execute(User $user, OtpPurpose $purpose, string $target, string $channel): IssuedCode
    {
        return DB::transaction(function () use ($user, $purpose, $target, $channel): IssuedCode {
            OtpCode::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->lockForUpdate()
                ->get()
                ->each(static function (OtpCode $previous): void {
                    $previous->consumed_at = now();
                    $previous->save();
                });

            $minutes = (int) $this->settings->get('otp.validity_minutes');
            $code = $this->generate();

            $otp = new OtpCode;
            $otp->user_id = $user->id;
            $otp->purpose = $purpose;
            $otp->target = $target;
            $otp->code_hash = OtpCode::fingerprint($code);
            $otp->attempts = 0;
            $otp->expires_at = now()->addMinutes($minutes);
            $otp->save();

            Notification::route($channel, $target)->notify(new OneTimeCodeNotification($purpose, $code, $minutes, $channel));

            return new IssuedCode($channel, $target, $code, $minutes);
        });
    }

    private function generate(): string
    {
        $digits = $this->config->integer('auth.code_digits');

        return str_pad((string) random_int(0, 10 ** $digits - 1), $digits, '0', STR_PAD_LEFT);
    }
}
