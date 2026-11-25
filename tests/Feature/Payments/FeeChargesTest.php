<?php

use App\Domain\Auth\Support\AuthSession;
use App\Domain\Members\Enums\FeesPeriodicity;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Payments\Enums\ChargeKind;
use App\Domain\Payments\Enums\ChargeStatus;
use App\Domain\Payments\Models\MemberCharge;
use App\Support\Config\SettingsService;
use Database\Seeders\TemplateSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/*
 * Échéances d'honoraires et impayés (06-IMPLEMENTATION-PLAN étape 3.7, F-PAY-04) : fees:generate crée
 * l'échéance de la période (idempotent), statuses:refresh passe en « impayée » à J+30 (RG-PAY-03), la
 * fiche compte les périodes impayées (RG-PAY-04) et alerte au-delà de 3 mois sans rien bloquer
 * (RG-PAY-05, CR-04).
 */

/**
 * @return list<MemberCharge>
 */
function feeCharges(Member $member): array
{
    return MemberCharge::query()->withoutGlobalScopes()->where('member_id', $member->id)->where('kind', ChargeKind::Fees)->orderBy('period_start')->get()->all();
}

function feeMember(array $attributes = []): Member
{
    return Member::factory()->active()->create(['fees_amount' => 15_000, 'fees_periodicity' => FeesPeriodicity::Monthly, 'adhesion_date' => '2026-01-10', ...$attributes]);
}

describe('fees:generate (F-PAY-04)', function () {
    it('crée l\'échéance de la période en cours selon la périodicité du dossier, au 1er de la période, TVA en sus', function () {
        $monthly = feeMember();
        $quarterly = feeMember(['fees_periodicity' => FeesPeriodicity::Quarterly, 'fees_amount' => 40_000]);
        $yearly = feeMember(['fees_periodicity' => FeesPeriodicity::Yearly, 'fees_amount' => 150_000]);

        $this->travelTo('2026-05-20 01:15:00');
        Artisan::call('fees:generate');

        expect(feeCharges($monthly))->toHaveCount(1)
            ->and(feeCharges($monthly)[0]->period_start->toDateString())->toBe('2026-05-01')
            ->and(feeCharges($monthly)[0]->period_end->toDateString())->toBe('2026-05-31')
            ->and(feeCharges($monthly)[0]->due_date->toDateString())->toBe('2026-05-01')
            ->and(feeCharges($monthly)[0]->amount_excl_tax)->toBe(15_000)
            ->and(feeCharges($monthly)[0]->tax_rate)->toBe(18.0)
            ->and(feeCharges($monthly)[0]->tax_amount)->toBe(2_700)
            ->and(feeCharges($monthly)[0]->amount)->toBe(17_700)
            ->and(feeCharges($monthly)[0]->status)->toBe(ChargeStatus::Due)
            ->and(feeCharges($quarterly)[0]->period_start->toDateString())->toBe('2026-04-01')
            ->and(feeCharges($quarterly)[0]->period_end->toDateString())->toBe('2026-06-30')
            ->and(feeCharges($quarterly)[0]->amount)->toBe(47_200)
            ->and(feeCharges($yearly)[0]->period_start->toDateString())->toBe('2026-01-01')
            ->and(feeCharges($yearly)[0]->period_end->toDateString())->toBe('2026-12-31');
    });

    it('est idempotente : relancée, elle ne crée aucun doublon ; le mois suivant ouvre une nouvelle période', function () {
        $member = feeMember();

        $this->travelTo('2026-05-20');
        Artisan::call('fees:generate');
        Artisan::call('fees:generate');
        expect(feeCharges($member))->toHaveCount(1);

        $this->travelTo('2026-06-02');
        Artisan::call('fees:generate');
        expect(feeCharges($member))->toHaveCount(2)
            ->and(feeCharges($member)[1]->period_start->toDateString())->toBe('2026-06-01');
    });

    it('prend le jour d\'échéance réglé par le CGA (fees.due_day)', function () {
        $member = feeMember();
        app(SettingsService::class)->set('fees.due_day', 10, $member->organization_id);

        $this->travelTo('2026-02-03');
        Artisan::call('fees:generate');

        expect(feeCharges($member)[0]->due_date->toDateString())->toBe('2026-02-10');
    });

    it('ignore les brouillons, les radiés, les honoraires nuls, une adhésion à venir et les CGA résiliés ; garde les radiations en cours', function () {
        $ignored = [
            feeMember(['status' => MemberStatus::Draft, 'membership_number' => null, 'adhesion_date' => null]),
            feeMember(['status' => MemberStatus::Radiated]),
            feeMember(['fees_amount' => 0]),
            feeMember(['adhesion_date' => '2026-06-01']),
            feeMember(['organization_id' => Organization::factory()->create(['status' => OrganizationStatus::Terminated])->id]),
        ];
        $pending = feeMember(['status' => MemberStatus::RadiationPending]);

        $this->travelTo('2026-05-20');
        Artisan::call('fees:generate');

        foreach ($ignored as $member) {
            expect(feeCharges($member))->toBeEmpty();
        }
        expect(feeCharges($pending))->toHaveCount(1);
    });
});

describe('impayés (RG-PAY-03 à 05)', function () {
    it('RG-PAY-03 passe « impayée » une échéance non réglée 30 jours après sa date, payée en partie comprise', function () {
        $member = feeMember();
        $due = MemberCharge::factory()->fees()->create(['member_id' => $member->id, 'due_date' => '2026-03-01', 'period_start' => '2026-03-01', 'period_end' => '2026-03-31']);
        $partial = MemberCharge::factory()->fees()->create(['member_id' => $member->id, 'due_date' => '2026-02-01', 'period_start' => '2026-02-01', 'period_end' => '2026-02-28', 'amount_paid' => 5_000, 'status' => ChargeStatus::Partial]);
        $paid = MemberCharge::factory()->fees()->create(['member_id' => $member->id, 'due_date' => '2026-01-01', 'period_start' => '2026-01-01', 'period_end' => '2026-01-31', 'status' => ChargeStatus::Paid]);

        $this->travelTo('2026-03-30');
        Artisan::call('statuses:refresh');
        expect($due->refresh()->status)->toBe(ChargeStatus::Due);

        $this->travelTo('2026-03-31');
        Artisan::call('statuses:refresh');
        Artisan::call('statuses:refresh');

        expect($due->refresh()->status)->toBe(ChargeStatus::Overdue)
            ->and($due->overdue_since?->toDateString())->toBe('2026-03-31')
            ->and($partial->refresh()->status)->toBe(ChargeStatus::Overdue)
            ->and($partial->overdue_since?->toDateString())->toBe('2026-03-03')
            ->and($paid->refresh()->status)->toBe(ChargeStatus::Paid);
    });

    it('RG-PAY-03 suit le délai réglé par le CGA (fees.overdue_days) et ne touche pas au droit d\'adhésion', function () {
        $member = feeMember();
        app(SettingsService::class)->set('fees.overdue_days', 10, $member->organization_id);
        $fees = MemberCharge::factory()->fees()->create(['member_id' => $member->id, 'due_date' => '2026-03-01', 'period_start' => '2026-03-01', 'period_end' => '2026-03-31']);
        $entryFee = MemberCharge::factory()->create(['member_id' => $member->id, 'due_date' => '2026-01-01']);

        $this->travelTo('2026-03-11');
        Artisan::call('statuses:refresh');

        expect($fees->refresh()->status)->toBe(ChargeStatus::Overdue)
            ->and($entryFee->refresh()->status)->toBe(ChargeStatus::Due);
    });

    it('RG-PAY-04 compte sur la fiche les périodes impayées ; un paiement solde l\'impayé', function () {
        $agent = documentAgent();
        $member = validatedMember($agent, ['fees_amount' => 15_000]);
        foreach (['2026-01-01', '2026-02-01'] as $start) {
            MemberCharge::factory()->fees()->create(['member_id' => $member->id, 'due_date' => $start, 'period_start' => $start, 'period_end' => date('Y-m-t', strtotime($start)), 'amount' => 15_000, 'status' => ChargeStatus::Overdue, 'overdue_since' => date('Y-m-d', strtotime($start.' +30 days'))]);
        }
        $this->travelTo('2026-03-15');

        inSpace($agent)->getJson("/api/v1/cga/members/{$member->id}")->assertOk()
            ->assertJsonPath('data.fees_overdue_periods', 2)
            ->assertJsonPath('data.alerts.0.code', 'fees_overdue')
            ->assertJsonPath('data.alerts.0.periods', 2)
            ->assertJsonPath('data.alerts.0.since', '2026-01-01')
            ->assertJsonPath('data.alerts.0.overdue', false);

        inSpace($agent)->postJson("/api/v1/cga/members/{$member->id}/payments", ['amount' => 15_000, 'paid_on' => '2026-03-15', 'method' => 'cash'])->assertCreated();

        inSpace($agent)->getJson("/api/v1/cga/members/{$member->id}")->assertJsonPath('data.fees_overdue_periods', 1)->assertJsonPath('data.alerts.0.since', '2026-02-01');
    });

    it('RG-PAY-05 alerte le gérant au-delà de 3 mois (réglable par CGA) sans jamais bloquer l\'attestation', function () {
        fakePdfConverter();
        Storage::fake();
        $this->seed(TemplateSeeder::class);
        config(['queue.default' => 'sync']);
        $agent = documentAgent();
        $member = validatedMember($agent);
        MemberCharge::factory()->fees()->create(['member_id' => $member->id, 'due_date' => '2026-01-01', 'period_start' => '2026-01-01', 'period_end' => '2026-01-31', 'status' => ChargeStatus::Overdue, 'overdue_since' => '2026-01-31']);

        $this->travelTo('2026-04-01');
        inSpace($agent)->getJson("/api/v1/cga/members/{$member->id}")->assertJsonPath('data.alerts.0.overdue', false);

        $this->travelTo('2026-04-02');
        inSpace($agent)->withSession([AuthSession::LAST_ACTIVITY_KEY => now()->getTimestamp()])->getJson("/api/v1/cga/members/{$member->id}")->assertJsonPath('data.alerts.0.overdue', true)
            ->assertJsonPath('data.attestation_blocked', false)
            ->assertJsonPath('data.can.block_attestation', true);
        Artisan::call('statuses:refresh');
        Artisan::call('fees:generate');

        expect(Member::query()->withoutGlobalScopes()->findOrFail($member->id)->attestation_blocked)->toBeFalse();
        inSpace($agent)->postJson("/api/v1/cga/members/{$member->id}/documents", ['type' => 'membership_certificate'])->assertStatus(202);

        app(SettingsService::class)->set('fees.alert_after_months', 6, $agent->organization_id);
        inSpace($agent)->getJson("/api/v1/cga/members/{$member->id}")->assertJsonPath('data.alerts.0.overdue', false);
    });
});
