<?php

namespace App\Domain\Users\Actions;

use App\Domain\Notifications\EventNotification;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\UserStatus;
use App\Domain\Users\Models\User;
use App\Support\Config\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * RG-ACC-06 / PRD §7 : désactive après 12 mois d'inactivité, informe à 23 mois, puis efface les données
 * personnelles à 24 mois. Les liens des journaux et des opérations restent rattachés à « Ancien utilisateur »
 * conformément à 05-BACKEND-SCHEMA §17 ; aucune donnée personnelle n'est copiée dans les journaux.
 */
final class ProcessUserInactivity
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @return array{disabled: int, notified: int, anonymized: int}
     */
    public function execute(CarbonImmutable $today): array
    {
        $disableMonths = (int) $this->settings->get('users.inactivity_disable_months');
        $deleteMonths = (int) $this->settings->get('users.inactivity_delete_months');
        $noticeMonths = (int) $this->settings->get('users.inactivity_notice_months_before_delete');
        if ($disableMonths >= $deleteMonths || $noticeMonths < 1 || $noticeMonths >= $deleteMonths) {
            throw new InvalidArgumentException('Les durées d’inactivité doivent satisfaire désactivation < avis < effacement.');
        }

        $disabled = $this->disable($today->subMonths($disableMonths));
        $notified = $this->notify($today->subMonths($deleteMonths - $noticeMonths), $today, $deleteMonths);
        $anonymized = $this->anonymize($today->subMonths($deleteMonths));

        return ['disabled' => $disabled, 'notified' => $notified, 'anonymized' => $anonymized];
    }

    private function disable(CarbonImmutable $inactiveBefore): int
    {
        $count = 0;
        $this->inactiveUsers($inactiveBefore)->where('status', UserStatus::Active)->orderBy('id')->chunkById(100, function ($users) use (&$count): void {
            foreach ($users as $candidate) {
                DB::transaction(function () use ($candidate, &$count): void {
                    $user = User::query()->lockForUpdate()->find($candidate->id);
                    if ($user === null || $user->status !== UserStatus::Active) {
                        return;
                    }
                    $user->status = UserStatus::Disabled;
                    $user->disabled_at = CarbonImmutable::now();
                    $user->save();
                    $count++;
                });
            }
        });

        return $count;
    }

    private function notify(CarbonImmutable $inactiveBefore, CarbonImmutable $today, int $deleteMonths): int
    {
        $platformId = Organization::query()->where('type', OrganizationType::Platform)->value('id');
        if (! is_string($platformId)) {
            throw new InvalidArgumentException('L’organisation plateforme est introuvable : avis de suppression impossible.');
        }

        $count = 0;
        $this->inactiveUsers($inactiveBefore)
            ->whereIn('status', [UserStatus::Active, UserStatus::Disabled])
            ->whereNull('inactivity_notice_sent_at')
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($platformId, $today, $deleteMonths, &$count): void {
                foreach ($users as $user) {
                    $inactiveSince = $user->last_login_at ?? $user->created_at;
                    $deleteDate = CarbonImmutable::parse($inactiveSince)->addMonths($deleteMonths)->toDateString();
                    Notification::sendNow($user, new EventNotification($platformId, null, 'account_inactivity_notice', [
                        'delete_date' => $deleteDate,
                    ]));

                    DB::transaction(function () use ($user, $today, &$count): void {
                        $locked = User::query()->lockForUpdate()->find($user->id);
                        if ($locked === null || $locked->inactivity_notice_sent_at !== null) {
                            return;
                        }
                        $locked->inactivity_notice_sent_at = $today;
                        $locked->save();
                        $count++;
                    });
                }
            });

        return $count;
    }

    private function anonymize(CarbonImmutable $inactiveBefore): int
    {
        $count = 0;
        $this->inactiveUsers($inactiveBefore)
            ->where('status', UserStatus::Disabled)
            ->whereNotNull('inactivity_notice_sent_at')
            ->orderBy('id')
            ->chunkById(100, function ($users) use (&$count): void {
                foreach ($users as $candidate) {
                    DB::transaction(function () use ($candidate, &$count): void {
                        $user = User::query()->lockForUpdate()->find($candidate->id);
                        if ($user === null || $user->status !== UserStatus::Disabled || $user->inactivity_notice_sent_at === null) {
                            return;
                        }

                        DB::table('sessions')->where('user_id', $user->id)->delete();
                        DB::table('notifications')->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->id)->delete();
                        DB::table('message_logs')->where('user_id', $user->id)->update(['recipient' => 'Ancien utilisateur']);
                        DB::table('notification_preferences')->where('user_id', $user->id)->delete();
                        DB::table('otp_codes')->where('user_id', $user->id)->delete();

                        $user->first_name = 'Ancien';
                        $user->last_name = 'utilisateur';
                        $user->phone = 'D'.substr($user->id, 0, 19);
                        $user->email = null;
                        $user->password = null;
                        $user->phone_verified_at = null;
                        $user->email_verified_at = null;
                        $user->last_login_at = null;
                        $user->last_space_membership_id = null;
                        $user->inactivity_notice_sent_at = null;
                        $user->status = UserStatus::Deleted;
                        $user->save();
                        $count++;
                    });
                }
            });

        return $count;
    }

    /**
     * @return Builder<User>
     */
    private function inactiveUsers(CarbonImmutable $inactiveBefore): Builder
    {
        return User::query()->where(static function (Builder $query) use ($inactiveBefore): void {
            $query->whereDate('last_login_at', '<=', $inactiveBefore->toDateString())
                ->orWhere(static fn (Builder $neverLoggedIn): Builder => $neverLoggedIn
                    ->whereNull('last_login_at')
                    ->whereDate('created_at', '<=', $inactiveBefore->toDateString()));
        });
    }
}
