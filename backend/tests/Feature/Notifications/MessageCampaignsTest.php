<?php

use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Members\Models\Member;
use App\Domain\Notifications\Actions\SetNotificationPreferences;
use App\Domain\Notifications\CampaignNotification;
use App\Domain\Notifications\Models\MessageCampaign;
use App\Domain\Notifications\Models\MessageLog;
use App\Domain\Notifications\Models\MessagingProvider;
use App\Domain\Notifications\Support\MessagingProviders;
use App\Domain\Payments\Enums\ChargeStatus;
use App\Domain\Payments\Models\MemberCharge;
use App\Domain\Referentials\Models\Sector;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\Tenancy;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

/*
 * Envois groupés d'un centre (06-IMPLEMENTATION-PLAN étape 6.5, F-COM-03, RG-COM-01, RG-COM-02) :
 * destinataires filtrés, canaux dans la limite du consentement, validation du gérant au-delà du seuil,
 * quota SMS, historique et délivrance.
 */

beforeEach(function () {
    config(['queue.default' => 'sync']);
    $this->travelTo('2026-11-03 10:00:00');
    $this->manager = Membership::factory()->role(Role::CgaManager)->create();
    $this->cga = $this->manager->organization;
    $this->accountant = Membership::factory()->role(Role::CgaAccountant)->create(['organization_id' => $this->cga->id]);
    $sms = new MessagingProvider;
    $sms->forceFill(['channel' => 'sms', 'driver' => 'log', 'label' => 'SMS', 'settings' => [], 'secrets' => [], 'is_active' => true])->save();
    app(MessagingProviders::class)->forget();
});

/**
 * Un dossier TEE avec son accès en ligne (consentement SMS donné).
 */
function campaignMember(Membership $manager, array $attributes = [], bool $consents = true): Membership
{
    $member = Member::factory()->active()->create(['organization_id' => $manager->organization_id, ...$attributes]);
    $access = Membership::factory()->role(Role::MemberOwner)->create(['organization_id' => $manager->organization_id, 'member_id' => $member->id]);
    app(SetNotificationPreferences::class)->execute($access->user, ['sms' => $consents, 'whatsapp' => false]);

    return $access;
}

function campaignPayload(array $overrides = []): array
{
    return ['title' => 'Déclaration de TVA', 'body' => 'Pensez à déclarer la TVA avant le 15.', 'channels' => ['platform', 'sms'], 'filter' => ['portfolio' => 'cga', 'regimes' => ['TEE']], ...$overrides];
}

it('prépare et envoie : plateforme et SMS aux adhérents filtrés qui y consentent ; historique de délivrance', function () {
    $tee = campaignMember($this->manager);
    $refusing = campaignMember($this->manager, [], false);
    $rme = campaignMember($this->manager, ['regime' => TaxRegime::Rme]);
    Member::factory()->active()->create(['organization_id' => $this->cga->id, 'email' => 'sans-acces@cga.test']);

    $id = inSpace($this->accountant)->postJson('/api/v1/cga/campaigns', campaignPayload())
        ->assertCreated()->assertJsonPath('data.recipients_count', 3)->assertJsonPath('data.status', 'draft')->json('data.id');
    inSpace($this->accountant)->postJson("/api/v1/cga/campaigns/{$id}/send")->assertOk()->assertJsonPath('data.status', 'sent');

    expect($tee->user->notifications()->sole()->data['body'])->toBe('Pensez à déclarer la TVA avant le 15.')
        ->and($refusing->user->notifications()->count())->toBe(1)
        ->and($rme->user->notifications()->count())->toBe(0)
        ->and(MessageLog::query()->where('campaign_id', $id)->where('channel', 'sms')->pluck('recipient')->all())->toBe([$tee->user->phone]);

    inSpace($this->accountant)->getJson('/api/v1/cga/campaigns')->assertOk()->assertJsonPath('data.0.delivery.sent', 1);
    inSpace($this->accountant)->postJson("/api/v1/cga/campaigns/{$id}/send")->assertStatus(409)->assertJsonPath('code', 'campaign_not_draft');
});

it('RG-COM-01 · au-delà du seuil, le gérant valide ou refuse ; le comptable ne valide pas', function () {
    app(SettingsService::class)->set('campaign.approval_threshold', 1, $this->cga->id);
    campaignMember($this->manager);
    campaignMember($this->manager);

    $id = inSpace($this->accountant)->postJson('/api/v1/cga/campaigns', campaignPayload(['channels' => ['platform']]))->json('data.id');
    inSpace($this->accountant)->postJson("/api/v1/cga/campaigns/{$id}/send")->assertOk()->assertJsonPath('data.status', 'pending_approval');
    expect($this->manager->user->notifications()->where('type', 'campaign_pending')->count())->toBe(1);

    inSpace($this->accountant)->postJson("/api/v1/cga/campaigns/{$id}/approve")->assertForbidden();
    inSpace($this->manager)->postJson("/api/v1/cga/campaigns/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'sent');
    inSpace($this->manager)->postJson("/api/v1/cga/campaigns/{$id}/approve")->assertStatus(409)->assertJsonPath('code', 'campaign_not_pending');

    $other = inSpace($this->accountant)->postJson('/api/v1/cga/campaigns', campaignPayload(['channels' => ['platform']]))->json('data.id');
    inSpace($this->accountant)->postJson("/api/v1/cga/campaigns/{$other}/send")->assertOk();
    inSpace($this->manager)->postJson("/api/v1/cga/campaigns/{$other}/reject", ['reason' => ''])->assertUnprocessable()->assertJsonValidationErrors('reason');
    inSpace($this->manager)->postJson("/api/v1/cga/campaigns/{$other}/reject", ['reason' => 'Message à revoir'])->assertOk()->assertJsonPath('data.status', 'rejected');
});

it('RG-COM-02 · avec le SMS, le quota restant du mois doit couvrir les destinataires', function () {
    app(SettingsService::class)->set('sms.monthly_quota', 1, $this->cga->id);
    campaignMember($this->manager);
    campaignMember($this->manager);

    $id = inSpace($this->manager)->postJson('/api/v1/cga/campaigns', campaignPayload())->json('data.id');
    inSpace($this->manager)->postJson("/api/v1/cga/campaigns/{$id}/send")->assertStatus(409)->assertJsonPath('code', 'sms_quota_insufficient')->assertJsonPath('details.remaining', 1);
    expect(Tenancy::bypass(static fn () => MessageCampaign::query()->findOrFail($id)->status->value, 'Test.'))->toBe('draft');
});

it('valide les canaux, le portefeuille permis et les dossiers du centre ; droits et cloisonnement', function () {
    $foreign = Member::factory()->active()->create();

    inSpace($this->manager)->postJson('/api/v1/cga/campaigns', campaignPayload(['channels' => ['fax'], 'filter' => ['member_ids' => [$foreign->id], 'regimes' => ['XX']]]))
        ->assertUnprocessable()->assertJsonValidationErrors(['channels.0', 'filter.member_ids.0', 'filter.regimes.0']);
    $secretary = Membership::factory()->role(Role::CgaSecretary)->create(['organization_id' => $this->cga->id]);
    inSpace($secretary)->postJson('/api/v1/cga/campaigns', campaignPayload())->assertForbidden();
    inSpace($secretary)->getJson('/api/v1/cga/campaigns')->assertForbidden();
    $billing = Membership::factory()->role(Role::CgaBillingOfficer)->create(['organization_id' => $this->cga->id]);
    inSpace($billing)->postJson('/api/v1/cga/campaigns', campaignPayload(['filter' => ['portfolio' => 'all']]))->assertForbidden();
    inSpace($this->accountant)->postJson('/api/v1/cga/campaigns', campaignPayload(['filter' => ['portfolio' => 'all']]))->assertCreated();

    $foreignCampaign = Membership::factory()->role(Role::CgaManager)->create();
    $foreignId = inSpace($foreignCampaign)->postJson('/api/v1/cga/campaigns', campaignPayload())->json('data.id');
    inSpace($this->manager)->postJson("/api/v1/cga/campaigns/{$foreignId}/send")->assertNotFound();
});

it('aperçu : destinataires comptés en direct (secteurs, honoraires impayés), seuil et quota SMS ; 422 et 403', function () {
    $sector = Sector::factory()->create();
    $inSector = campaignMember($this->manager, ['sector_id' => $sector->id]);
    $late = campaignMember($this->manager);
    campaignMember($this->manager);
    MemberCharge::factory()->fees()->create(['organization_id' => $this->cga->id, 'member_id' => $late->member_id, 'status' => ChargeStatus::Overdue]);

    inSpace($this->accountant)->postJson('/api/v1/cga/campaigns/preview', ['channels' => ['platform', 'sms'], 'filter' => ['portfolio' => 'cga']])
        ->assertOk()->assertJsonPath('data.recipients_count', 3)->assertJsonPath('data.sms_needed', 3)
        ->assertJsonPath('data.sms_remaining', 200)->assertJsonPath('data.approval_threshold', 50)->assertJsonPath('data.needs_approval', false);
    inSpace($this->accountant)->postJson('/api/v1/cga/campaigns/preview', ['channels' => ['platform'], 'filter' => ['sector_ids' => [$sector->id]]])
        ->assertOk()->assertJsonPath('data.recipients_count', 1)->assertJsonPath('data.sms_needed', 0);
    inSpace($this->accountant)->postJson('/api/v1/cga/campaigns/preview', ['channels' => ['platform'], 'filter' => ['unpaid_fees' => true]])
        ->assertOk()->assertJsonPath('data.recipients_count', 1);
    $id = inSpace($this->accountant)->postJson('/api/v1/cga/campaigns', campaignPayload(['channels' => ['platform'], 'filter' => ['sector_ids' => [$sector->id]]]))
        ->assertCreated()->assertJsonPath('data.recipients_count', 1)->json('data.id');
    inSpace($this->accountant)->postJson("/api/v1/cga/campaigns/{$id}/send")->assertOk();
    expect($inSector->user->notifications()->where('type', 'campaign_message')->count())->toBe(1)
        ->and($late->user->notifications()->where('type', 'campaign_message')->count())->toBe(0);

    inSpace($this->accountant)->postJson('/api/v1/cga/campaigns/preview', ['channels' => [], 'filter' => ['sector_ids' => ['inconnu']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['channels', 'filter.sector_ids.0']);
    $secretary = Membership::factory()->role(Role::CgaSecretary)->create(['organization_id' => $this->cga->id]);
    inSpace($secretary)->postJson('/api/v1/cga/campaigns/preview', ['channels' => ['platform'], 'filter' => []])->assertForbidden();
    inSpace($this->manager)->getJson('/api/v1/cga/campaigns')->assertOk()
        ->assertJsonPath('meta.sms_remaining', 200)->assertJsonPath('meta.approval_threshold', 50)->assertJsonPath('meta.can.approve', true);
    inSpace($this->accountant)->getJson('/api/v1/cga/campaigns')->assertOk()->assertJsonPath('meta.can.approve', false);
});

it('RG-COM-01 · envoyée par le gérant au-delà du seuil, la campagne part aussitôt, validée par lui', function () {
    app(SettingsService::class)->set('campaign.approval_threshold', 1, $this->cga->id);
    campaignMember($this->manager);
    campaignMember($this->manager);

    $id = inSpace($this->manager)->postJson('/api/v1/cga/campaigns', campaignPayload(['channels' => ['platform']]))->json('data.id');
    inSpace($this->manager)->postJson("/api/v1/cga/campaigns/{$id}/send")->assertOk()->assertJsonPath('data.status', 'sent');

    $campaign = Tenancy::bypass(static fn () => MessageCampaign::query()->findOrFail($id), 'Test.');
    expect($campaign->approved_by)->toBe($this->manager->user_id)
        ->and($this->manager->user->notifications()->where('type', 'campaign_pending')->count())->toBe(0);
});

it('un adhérent sans accès en ligne reçoit le message par e-mail seulement', function () {
    Notification::fake();
    Member::factory()->active()->create(['organization_id' => $this->cga->id, 'regime' => TaxRegime::Tee, 'email' => 'boutique@client.ci']);

    $id = inSpace($this->manager)->postJson('/api/v1/cga/campaigns', campaignPayload(['channels' => ['platform', 'email', 'sms']]))->json('data.id');
    inSpace($this->manager)->postJson("/api/v1/cga/campaigns/{$id}/send")->assertOk()->assertJsonPath('data.status', 'sent');

    Notification::assertSentOnDemand(CampaignNotification::class, static function (CampaignNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool {
        $mail = $notification->toMail($notifiable);

        return $channels === ['mail'] && $notifiable->routes['mail'] === 'boutique@client.ci'
            && $mail->subject === 'Déclaration de TVA' && $notification->replacements()['body'] === 'Pensez à déclarer la TVA avant le 15.';
    });
});
