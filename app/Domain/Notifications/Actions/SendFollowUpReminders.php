<?php

namespace App\Domain\Notifications\Actions;

use App\Domain\Documents\Support\DocumentFormat;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\PieceStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\MemberPiece;
use App\Domain\Notifications\Support\Recipients;
use App\Domain\Payments\Enums\ChargeStatus;
use App\Domain\Payments\Models\MemberCharge;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Rappels de suivi des dossiers (02-TRD §11, PRD M13) :
 * - `reminders:fees` : honoraires impayés, à l'adhérent, le jour du passage en impayé puis tous les
 *   fees.reminder_every_days jours tant qu'ils restent dus ;
 * - `reminders:documents` : pièces attendues depuis pieces.reminder_days jours, puis à nouveau à chaque
 *   période, aux agents du centre et à l'adhérent.
 * Aucune sanction : ce sont des rappels (CR-04). Un rappel du jour n'est jamais renvoyé.
 */
class SendFollowUpReminders
{
    public function __construct(
        private readonly Recipients $recipients,
        private readonly SettingsService $settings,
    ) {}

    public function fees(CarbonImmutable $today): int
    {
        return Tenancy::bypass(function () use ($today): int {
            $sent = 0;
            MemberCharge::query()
                ->where('status', ChargeStatus::Overdue)
                ->whereNotNull('overdue_since')
                ->whereHas('member', static fn ($member) => $member->whereIn('status', [MemberStatus::Active, MemberStatus::RadiationPending]))
                ->get()
                ->groupBy('member_id')
                ->each(function ($charges, string $memberId) use ($today, &$sent): void {
                    $member = Member::query()->findOrFail($memberId);
                    $every = max(1, (int) $this->settings->get('fees.reminder_every_days', $member->organization_id));
                    $remind = $charges->contains(static fn (MemberCharge $charge): bool => ((int) CarbonImmutable::parse($charge->overdue_since)->diffInDays($today)) % $every === 0);
                    if (! $remind || ! Cache::add("reminders:fees:{$memberId}:{$today->toDateString()}", true, $today->addDays(2))) {
                        return;
                    }
                    $due = (int) $charges->sum(static fn (MemberCharge $charge): int => $charge->amount - $charge->amount_paid);
                    $this->recipients->notify($this->recipients->memberAccesses($memberId), 'fees_unpaid', ['count' => $charges->count(), 'amount' => DocumentFormat::amount($due)], '/adherent/honoraires');
                    $sent++;
                });

            return $sent;
        }, 'Tâche reminders:fees : honoraires impayés de tous les CGA (PRD M13).');
    }

    public function pieces(CarbonImmutable $today): int
    {
        return Tenancy::bypass(function () use ($today): int {
            $sent = 0;
            MemberPiece::query()
                ->where('status', PieceStatus::Expected)
                ->whereHas('member', static fn ($member) => $member->whereIn('status', [MemberStatus::Draft, MemberStatus::Active, MemberStatus::RadiationPending]))
                ->with('member')
                ->get()
                ->filter(function (MemberPiece $piece) use ($today): bool {
                    $days = (int) $this->settings->get('pieces.reminder_days', $piece->organization_id);
                    $since = CarbonImmutable::parse($piece->last_reminder_at ?? $piece->created_at);

                    return $since->startOfDay()->addDays($days)->lessThanOrEqualTo($today);
                })
                ->groupBy('member_id')
                ->each(function ($pieces, string $memberId) use (&$sent): void {
                    /** @var MemberPiece $first */
                    $first = $pieces->first();
                    $values = ['member' => $first->member->company_name, 'count' => $pieces->count()];
                    $this->recipients->notify($this->recipients->agents($first->organization_id, 'members.update'), 'pieces_pending', $values, "/cga/adherents/{$memberId}");
                    $this->recipients->notify($this->recipients->memberAccesses($memberId), 'pieces_pending', $values, '/adherent');
                    $pieces->each(static function (MemberPiece $piece): void {
                        $piece->last_reminder_at = now();
                        $piece->save();
                    });
                    $sent++;
                });

            return $sent;
        }, 'Tâche reminders:documents : pièces en attente de tous les CGA (PRD M13).');
    }
}
