<?php

namespace App\Domain\Users\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Users\Enums\UserStatus;
use App\Domain\Users\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Efface les données personnelles d'un compte (PRD §7 : utilisateur inactif à 24 mois ; RG-ORG-07 et décision
 * 0018 §3 : comptes d'un CGA résilié). Les liens des journaux et des opérations restent rattachés à « Ancien
 * utilisateur » (05-BACKEND-SCHEMA §17). L'enregistrement est silencieux pour que le journal d'audit ne recopie pas
 * les valeurs effacées ; l'audit consigne seulement l'événement, sans valeurs. À appeler dans une transaction.
 */
final class AnonymizeUser
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $user, string $reason): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
        DB::table('notifications')->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->id)->delete();
        DB::table('message_logs')->where('user_id', $user->id)->update(['recipient' => __('retention.former_user')]);
        DB::table('notification_preferences')->where('user_id', $user->id)->delete();
        DB::table('otp_codes')->where('user_id', $user->id)->delete();

        $user->first_name = __('retention.former_user_first_name');
        $user->last_name = __('retention.former_user_last_name');
        $user->phone = 'D'.substr($user->id, 0, 19);
        $user->email = null;
        $user->password = null;
        $user->phone_verified_at = null;
        $user->email_verified_at = null;
        $user->last_login_at = null;
        $user->last_space_membership_id = null;
        $user->inactivity_notice_sent_at = null;
        $user->status = UserStatus::Deleted;
        $user->saveQuietly();

        $this->audit->record('user_anonymized', $user, context: ['reason' => $reason]);
    }
}
