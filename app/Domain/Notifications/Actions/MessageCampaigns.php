<?php

namespace App\Domain\Notifications\Actions;

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Models\Member;
use App\Domain\Notifications\CampaignNotification;
use App\Domain\Notifications\Enums\CampaignStatus;
use App\Domain\Notifications\Models\MessageCampaign;
use App\Domain\Notifications\Support\Recipients;
use App\Domain\Payments\Enums\ChargeKind;
use App\Domain\Payments\Enums\ChargeStatus;
use App\Domain\Payments\Models\MemberCharge;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Support\Config\SettingsService;
use App\Support\Http\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Envois groupés d'un centre à ses adhérents (F-COM-03) :
 * - préparation : objet, message, canaux, filtre (portefeuille, régimes, statut, dossiers choisis) et
 *   nombre de destinataires ;
 * - RG-COM-01 : au-delà de campaign.approval_threshold destinataires, l'envoi attend la validation du
 *   gérant (notifié) ; il peut aussi le refuser avec un motif ;
 * - RG-COM-02 : avec le SMS, le quota restant du mois doit couvrir les destinataires ; chaque SMS est
 *   décompté au départ ;
 * - envoi : à chaque accès en ligne des dossiers retenus (plateforme, e-mail, SMS, WhatsApp selon le
 *   consentement), par e-mail aux dossiers sans accès ; historique par message_logs.
 */
class MessageCampaigns
{
    public function __construct(
        private readonly Recipients $recipients,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @param  list<string>  $channels
     * @param  array{portfolio: string, regimes: list<string>, sector_ids?: list<string>, unpaid_fees?: bool, member_ids: list<string>}  $filter
     */
    public function prepare(string $organizationId, string $title, string $body, array $channels, array $filter, User $actor): MessageCampaign
    {
        return DB::transaction(function () use ($organizationId, $title, $body, $channels, $filter, $actor): MessageCampaign {
            $campaign = new MessageCampaign;
            $campaign->organization_id = $organizationId;
            $campaign->title = $title;
            $campaign->body = $body;
            $campaign->channels = $channels;
            $campaign->recipient_filter = $filter;
            $campaign->recipients_count = $this->members($organizationId, $filter)->count();
            $campaign->status = CampaignStatus::Draft;
            $campaign->requested_by = $actor->id;
            $campaign->save();

            return $campaign;
        });
    }

    /**
     * « Envoyer » : au-delà du seuil, en attente du gérant (RG-COM-01) ; sinon, envoi immédiat. Envoyée par
     * qui valide (`$approver`, le gérant), la campagne part aussitôt, validée par lui (03-APPFLOW §4.3).
     *
     * @throws BusinessRuleException campaign_not_draft, sms_quota_insufficient
     */
    public function submit(MessageCampaign $campaign, CarbonImmutable $now, ?User $approver = null): MessageCampaign
    {
        return DB::transaction(function () use ($campaign, $now, $approver): MessageCampaign {
            $campaign = MessageCampaign::query()->lockForUpdate()->findOrFail($campaign->id);
            if ($campaign->status !== CampaignStatus::Draft) {
                throw new BusinessRuleException('campaign_not_draft');
            }
            if ($campaign->recipients_count > $this->approvalThreshold($campaign->organization_id) && $approver !== null) {
                $campaign->status = CampaignStatus::Approved;
                $campaign->approved_by = $approver->id;
                $campaign->approved_at = $now;
                $campaign->save();

                return $this->send($campaign, $now);
            }
            if ($campaign->recipients_count > $this->approvalThreshold($campaign->organization_id)) {
                $campaign->status = CampaignStatus::PendingApproval;
                $campaign->save();
                $this->recipients->notify($this->recipients->agents($campaign->organization_id, 'communications.bulk.approve'), 'campaign_pending', ['title' => $campaign->title, 'count' => $campaign->recipients_count], '/cga/communications');

                return $campaign;
            }

            return $this->send($campaign, $now);
        });
    }

    /**
     * Validation du gérant (RG-COM-01), puis envoi.
     *
     * @throws BusinessRuleException campaign_not_pending, sms_quota_insufficient
     */
    public function approve(MessageCampaign $campaign, User $actor, CarbonImmutable $now): MessageCampaign
    {
        return DB::transaction(function () use ($campaign, $actor, $now): MessageCampaign {
            $campaign = MessageCampaign::query()->lockForUpdate()->findOrFail($campaign->id);
            if ($campaign->status !== CampaignStatus::PendingApproval) {
                throw new BusinessRuleException('campaign_not_pending');
            }
            $campaign->status = CampaignStatus::Approved;
            $campaign->approved_by = $actor->id;
            $campaign->approved_at = $now;
            $campaign->save();

            return $this->send($campaign, $now);
        });
    }

    /**
     * @throws BusinessRuleException campaign_not_pending
     */
    public function reject(MessageCampaign $campaign, string $reason, User $actor): MessageCampaign
    {
        return DB::transaction(function () use ($campaign, $reason, $actor): MessageCampaign {
            $campaign = MessageCampaign::query()->lockForUpdate()->findOrFail($campaign->id);
            if ($campaign->status !== CampaignStatus::PendingApproval) {
                throw new BusinessRuleException('campaign_not_pending');
            }
            $campaign->status = CampaignStatus::Rejected;
            $campaign->rejection_reason = $reason;
            $campaign->approved_by = $actor->id;
            $campaign->save();

            return $campaign;
        });
    }

    /**
     * Aperçu d'un envoi (03-APPFLOW §4.3, étapes 1 et 2) : nombre de destinataires en direct, seuil de
     * validation, SMS restants du mois et SMS nécessaires.
     *
     * @param  list<string>  $channels
     * @param  array{portfolio: string, regimes: list<string>, sector_ids?: list<string>, unpaid_fees?: bool, member_ids: list<string>}  $filter
     * @return array{recipients_count: int, approval_threshold: int, needs_approval: bool, sms_needed: int, sms_remaining: int}
     */
    public function audience(string $organizationId, array $channels, array $filter, CarbonImmutable $now): array
    {
        $count = $this->members($organizationId, $filter)->count();
        $threshold = $this->approvalThreshold($organizationId);

        return [
            'recipients_count' => $count,
            'approval_threshold' => $threshold,
            'needs_approval' => $count > $threshold,
            'sms_needed' => in_array('sms', $channels, true) ? $count : 0,
            'sms_remaining' => $this->remainingSms($organizationId, $now),
        ];
    }

    public function approvalThreshold(string $organizationId): int
    {
        return (int) $this->settings->get('campaign.approval_threshold', $organizationId);
    }

    /**
     * Dossiers retenus par le filtre : portefeuille (CGA par défaut), régimes, secteurs, honoraires impayés
     * (échéance d'honoraires en retard), dossiers choisis ; dossiers actifs ou en radiation.
     *
     * @param  array{portfolio: string, regimes: list<string>, sector_ids?: list<string>, unpaid_fees?: bool, member_ids: list<string>}  $filter
     * @return Builder<Member>
     */
    public function members(string $organizationId, array $filter): Builder
    {
        return Member::query()
            ->where('organization_id', $organizationId)
            ->whereIn('status', [MemberStatus::Active, MemberStatus::RadiationPending])
            ->when($filter['portfolio'] !== 'all', static fn (Builder $query) => $query->where('portfolio', Portfolio::from($filter['portfolio'])))
            ->when($filter['regimes'] !== [], static fn (Builder $query) => $query->whereIn('regime', $filter['regimes']))
            ->when(($filter['sector_ids'] ?? []) !== [], static fn (Builder $query) => $query->whereIn('sector_id', $filter['sector_ids'] ?? []))
            ->when($filter['unpaid_fees'] ?? false, static fn (Builder $query) => $query->whereIn('id', MemberCharge::query()
                ->where('organization_id', $organizationId)
                ->where('kind', ChargeKind::Fees)
                ->where('status', ChargeStatus::Overdue)
                ->select('member_id')))
            ->when($filter['member_ids'] !== [], static fn (Builder $query) => $query->whereKey($filter['member_ids']));
    }

    /**
     * @throws BusinessRuleException sms_quota_insufficient
     */
    private function send(MessageCampaign $campaign, CarbonImmutable $now): MessageCampaign
    {
        if (in_array('sms', $campaign->channels, true)) {
            $remaining = $this->remainingSms($campaign->organization_id, $now);
            if ($campaign->recipients_count > $remaining) {
                throw new BusinessRuleException('sms_quota_insufficient', __('errors.sms_quota_insufficient', ['remaining' => $remaining]), ['remaining' => $remaining]);
            }
        }
        $campaign->status = CampaignStatus::Sending;
        $campaign->save();

        /** @var array{portfolio: string, regimes: list<string>, sector_ids?: list<string>, unpaid_fees?: bool, member_ids: list<string>} $filter */
        $filter = $campaign->recipient_filter;
        $this->members($campaign->organization_id, $filter)->orderBy('id')->each(function (Member $member) use ($campaign): void {
            $accesses = $this->recipients->memberAccesses($member->id);
            if ($accesses->isEmpty()) {
                if (filled($member->email)) {
                    Notification::route('mail', $member->email)->notify($this->notification($campaign, null));
                }

                return;
            }
            $this->each($accesses, $campaign);
        });

        $campaign->status = CampaignStatus::Sent;
        $campaign->sent_at = $now;
        $campaign->save();

        return $campaign;
    }

    /**
     * @param  Collection<int, Membership>  $accesses
     */
    private function each(Collection $accesses, MessageCampaign $campaign): void
    {
        foreach ($accesses as $access) {
            $access->user->notify($this->notification($campaign, $access->id));
        }
    }

    private function notification(MessageCampaign $campaign, ?string $membershipId): CampaignNotification
    {
        return new CampaignNotification($campaign->organization_id, $membershipId, $campaign->id, $campaign->title, $campaign->body, $campaign->channels);
    }

    /** SMS encore disponibles ce mois pour le centre (quota inclus et packs, moins les SMS partis). */
    public function remainingSms(string $organizationId, CarbonImmutable $now): int
    {
        $row = DB::table('sms_quotas')->where('organization_id', $organizationId)->where('month', $now->startOfMonth()->toDateString())->first();
        $included = $row === null ? (int) $this->settings->get('sms.monthly_quota', $organizationId) : (int) $row->included + (int) $row->extra;

        return max(0, $included - (int) ($row->used ?? 0));
    }
}
