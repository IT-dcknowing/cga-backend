<?php

use App\Domain\Concerns\Enums\ConcernSide;
use App\Domain\Concerns\Models\Concern;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Exports\Models\Export;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\RadiationInitiator;
use App\Domain\Members\Enums\RadiationOutcome;
use App\Domain\Members\Enums\RadiationReason;
use App\Domain\Members\Enums\RadiationStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\MemberPayment;
use App\Domain\Organizations\Enums\SubscriptionStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Payments\Enums\CertificationMode;
use App\Domain\Platform\Actions\PurgeRetention;
use App\Domain\Reports\Models\QuarterlyReport;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Enums\UserStatus;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Support\Files\StorageService;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Purges et anonymisations (PRD §7, RG-ORG-07, décision 0018 §3) : seules les données sans durée légale partent,
 * jamais un dossier, un document légal ni un rapport avant son échéance.
 */

const PURGE_TODAY = '2026-11-05 12:00:00';

beforeEach(function () {
    Storage::fake('private');
});

function purgeToday(): CarbonImmutable
{
    return CarbonImmutable::parse(PURGE_TODAY);
}

function purgeRadiation(Member $member, CarbonImmutable $effective, RadiationStatus $status = RadiationStatus::Validated): void
{
    $user = User::factory()->create();
    DB::table('radiations')->insert([
        'id' => strtolower((string) Str::ulid()), 'organization_id' => $member->organization_id, 'member_id' => $member->id,
        'initiator' => RadiationInitiator::Cga->value, 'reason' => RadiationReason::ZoneChange->value, 'status' => $status->value,
        'effective_date' => $effective->toDateString(), 'observation' => 'Changement de zone', 'requested_by' => $user->id,
        'requested_at' => $effective, 'validated_by' => $user->id, 'validated_at' => $effective,
        'outcome' => RadiationOutcome::Closed->value, 'created_at' => $effective, 'updated_at' => $effective,
    ]);
}

function purgeFile(?string $organizationId, string $type = 'pieces'): App\Domain\Documents\Models\File
{
    return app(StorageService::class)->store("%PDF-1.4\n%%EOF\n", 'piece.pdf', $type, $organizationId);
}

function purgePiece(Member $member): App\Domain\Documents\Models\File
{
    $file = purgeFile($member->organization_id);
    DB::table('member_pieces')->insert([
        'id' => strtolower((string) Str::ulid()), 'member_id' => $member->id, 'organization_id' => $member->organization_id,
        'piece_type' => 'id_document', 'status' => 'received', 'file_id' => $file->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $file;
}

function radiatedMember(Organization $organization, string $name, CarbonImmutable $effective): Member
{
    $member = Member::factory()->create([
        'organization_id' => $organization->id, 'company_name' => $name, 'owner_last_name' => 'Kouassi',
        'owner_id_document_number' => 'CI0012345678', 'email' => 'contact@alpha.ci', 'status' => MemberStatus::Radiated,
        'cga_contract_signed_on' => $effective->subYears(2)->toDateString(),
    ]);
    purgeRadiation($member, $effective);

    return $member;
}

function terminatedOrganization(CarbonImmutable $due): Organization
{
    $organization = Organization::factory()->cga()->create();
    $organization->subscription_status = SubscriptionStatus::Terminated;
    $organization->data_deletion_due_at = $due;
    $organization->save();

    return $organization;
}

it('PRD §7 : un dossier radié depuis 10 ans est anonymisé, ses pièces partent avec leurs fichiers, l\'audit ne recopie rien', function () {
    $organization = Organization::factory()->cga()->create();
    [$old, $recent, $active, $file] = Tenancy::bypass(static fn (): array => [
        $old = radiatedMember($organization, 'Société Alpha Ancienne', purgeToday()->subYears(10)->subDay()),
        radiatedMember($organization, 'Société Bêta Récente', purgeToday()->subYears(9)),
        Member::factory()->active()->create(['organization_id' => $organization->id, 'company_name' => 'Société Active']),
        purgePiece($old),
    ], 'Test : dossiers à purger.');
    $updatesBefore = DB::table('audit_logs')->where('subject_id', $old->id)->where('event', 'updated')->count();

    $stats = app(PurgeRetention::class)->execute(purgeToday());

    $old = Tenancy::bypass(static fn () => $old->fresh(), 'Test : lecture.');
    expect($stats['anonymized_members'])->toBe(1)
        ->and($old->company_name)->toBe('[Anonymisé]')
        ->and($old->owner_last_name)->toBe('Anonymisé')
        ->and($old->owner_id_document_number)->toBeNull()
        ->and($old->email)->toBeNull()
        ->and($old->anonymized_at)->not->toBeNull()
        ->and(Tenancy::bypass(static fn () => $recent->fresh()->company_name, 'Test : lecture.'))->toBe('Société Bêta Récente')
        ->and(Tenancy::bypass(static fn () => $active->fresh()->company_name, 'Test : lecture.'))->toBe('Société Active');
    $this->assertDatabaseMissing('member_pieces', ['member_id' => $old->id]);
    $this->assertDatabaseMissing('files', ['id' => $file->id]);
    Storage::disk('private')->assertMissing($file->path);
    expect(DB::table('audit_logs')->where('subject_id', $old->id)->where('event', 'updated')->count())->toBe($updatesBefore)
        ->and(DB::table('audit_logs')->where('subject_id', $old->id)->where('event', 'member_anonymized')->whereNull('changes')->exists())->toBeTrue();

    expect(app(PurgeRetention::class)->execute(purgeToday())['anonymized_members'])->toBe(0);
});

it('PRD §7 : un dossier dont la radiation a été annulée n\'est pas anonymisé', function () {
    $organization = Organization::factory()->cga()->create();
    Tenancy::bypass(static function () use ($organization): void {
        $member = Member::factory()->active()->create(['organization_id' => $organization->id]);
        purgeRadiation($member, purgeToday()->subYears(11), RadiationStatus::Cancelled);
    }, 'Test : radiation annulée.');

    expect(app(PurgeRetention::class)->execute(purgeToday())['anonymized_members'])->toBe(0);
});

it('PRD §7 : contrats, attestations, reçus et factures partent après 10 ans ; les rapports et les documents récents restent', function () {
    [$receipt, $payment, $report, $recent, $pdf] = Tenancy::bypass(static function (): array {
        $pdf = purgeFile(null, 'documents');
        $receipt = GeneratedDocument::factory()->create(['type' => DocumentType::Receipt, 'issued_at' => purgeToday()->subYears(11), 'pdf_file_id' => $pdf->id]);
        $payment = MemberPayment::factory()->create(['member_id' => $receipt->member_id, 'receipt_document_id' => $receipt->id]);
        DB::table('document_verifications')->insert(['document_id' => $receipt->id, 'verified_at' => now()]);
        $report = GeneratedDocument::factory()->create(['type' => DocumentType::QuarterlyReportModern, 'issued_at' => purgeToday()->subYears(11)]);
        $recent = GeneratedDocument::factory()->create(['type' => DocumentType::MembershipContract, 'issued_at' => purgeToday()->subYears(9)]);

        return [$receipt, $payment, $report, $recent, $pdf];
    }, 'Test : documents à purger.');

    $stats = app(PurgeRetention::class)->execute(purgeToday());

    expect($stats['purged_documents'])->toBe(1);
    $this->assertDatabaseMissing('generated_documents', ['id' => $receipt->id]);
    $this->assertDatabaseMissing('document_verifications', ['document_id' => $receipt->id]);
    $this->assertDatabaseHas('member_payments', ['id' => $payment->id, 'receipt_document_id' => null]);
    $this->assertDatabaseMissing('files', ['id' => $pdf->id]);
    $this->assertDatabaseHas('generated_documents', ['id' => $report->id]);
    $this->assertDatabaseHas('generated_documents', ['id' => $recent->id]);
});

it('PRD §7 : préoccupations (5 ans) et messages (2 ans) suivent les durées paramétrées', function () {
    $organization = Organization::factory()->cga()->create();
    Tenancy::bypass(static function () use ($organization): void {
        Concern::factory()->create(['organization_id' => $organization->id, 'created_at' => purgeToday()->subYears(6)]);
        Concern::factory()->create(['organization_id' => $organization->id, 'created_at' => purgeToday()->subYears(4)]);
        DB::table('message_logs')->insert([
            ['organization_id' => $organization->id, 'channel' => 'sms', 'recipient' => '+22501020304', 'status' => 'sent', 'created_at' => purgeToday()->subYears(3)],
            ['organization_id' => $organization->id, 'channel' => 'sms', 'recipient' => '+22501020304', 'status' => 'sent', 'created_at' => purgeToday()->subYear()],
        ]);
    }, 'Test : préoccupations et messages.');

    $stats = app(PurgeRetention::class)->execute(purgeToday());

    expect($stats['purged_concerns'])->toBe(1)
        ->and($stats['purged_messages'])->toBe(1)
        ->and(DB::table('concerns')->count())->toBe(1)
        ->and(DB::table('message_logs')->count())->toBe(1);
});

it('RG-ORG-07 et décision 0018 §3 : un CGA résilié perd ses données sans durée légale, garde dossiers, documents et rapports', function () {
    $organization = terminatedOrganization(purgeToday()->subDays(10));
    $other = Organization::factory()->cga()->create();

    $agent = Membership::factory()->create(['organization_id' => $organization->id]);
    $multi = Membership::factory()->create(['organization_id' => $organization->id]);
    $elsewhere = Membership::factory()->create(['organization_id' => $other->id, 'user_id' => $multi->user_id]);
    $cited = Membership::factory()->role(Role::CgaManager)->create(['organization_id' => $organization->id]);

    [$member, $contract, $report, $pieceFile, $concern, $attachment, $otherConcern] = Tenancy::bypass(static function () use ($organization, $other, $agent, $cited): array {
        Export::factory()->create(['membership_id' => $cited->id]);
        $member = Member::factory()->active()->create(['organization_id' => $organization->id, 'company_name' => 'Dossier conservé']);
        $contract = GeneratedDocument::factory()->create(['member_id' => $member->id]);
        $report = QuarterlyReport::factory()->create(['organization_id' => $organization->id]);
        $pieceFile = purgePiece($member);

        $concern = Concern::factory()->create(['organization_id' => $organization->id, 'member_id' => $member->id]);
        $messageId = strtolower((string) Str::ulid());
        DB::table('concern_messages')->insert([
            'id' => $messageId, 'organization_id' => $organization->id, 'concern_id' => $concern->id, 'author_user_id' => $agent->user_id,
            'author_side' => ConcernSide::Requester->value, 'body' => 'Bonjour', 'created_at' => now(),
        ]);
        $attachment = purgeFile($organization->id, 'concerns');
        DB::table('concern_message_files')->insert(['organization_id' => $organization->id, 'message_id' => $messageId, 'file_id' => $attachment->id]);
        $otherConcern = Concern::factory()->create(['organization_id' => $other->id]);

        DB::table('message_logs')->insert(['organization_id' => $organization->id, 'channel' => 'sms', 'recipient' => '+22501020304', 'status' => 'sent']);
        DB::table('settings')->insert(['organization_id' => $organization->id, 'key' => 'fees.alert_after_months', 'value' => json_encode(4), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('role_permissions')->insert(['organization_id' => $organization->id, 'role' => Role::CgaAccountant->value, 'permission' => 'members.view', 'granted' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('invoicing_providers')->insert(['id' => strtolower((string) Str::ulid()), 'organization_id' => $organization->id, 'mode' => CertificationMode::Manual->value, 'created_at' => now(), 'updated_at' => now()]);

        return [$member, $contract, $report, $pieceFile, $concern, $attachment, $otherConcern];
    }, 'Test : données d\'un CGA résilié.');

    $morph = (new User)->getMorphClass();
    DB::table('notifications')->insert([
        ['id' => (string) Str::uuid(), 'type' => 'event', 'notifiable_type' => $morph, 'notifiable_id' => $multi->user_id, 'data' => json_encode(['organization_id' => $organization->id]), 'created_at' => now(), 'updated_at' => now()],
        ['id' => (string) Str::uuid(), 'type' => 'event', 'notifiable_type' => $morph, 'notifiable_id' => $multi->user_id, 'data' => json_encode(['organization_id' => $other->id]), 'created_at' => now(), 'updated_at' => now()],
    ]);

    $stats = app(PurgeRetention::class)->execute(purgeToday());

    expect($stats['purged_terminated_organizations'])->toBe(1);

    // Restent : l'organisation, les dossiers, les documents légaux et les rapports.
    $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'data_deletion_due_at' => null]);
    $this->assertDatabaseHas('members', ['id' => $member->id, 'company_name' => 'Dossier conservé']);
    $this->assertDatabaseHas('generated_documents', ['id' => $contract->id]);
    $this->assertDatabaseHas('quarterly_reports', ['id' => $report->id]);

    // Comptes d'accès : rattachements supprimés, ou désactivés s'ils sont encore cités ; utilisateurs sans autre
    // rattachement anonymisés.
    $this->assertDatabaseMissing('memberships', ['id' => $agent->id]);
    $this->assertDatabaseMissing('memberships', ['id' => $multi->id]);
    $this->assertDatabaseHas('memberships', ['id' => $elsewhere->id, 'status' => MembershipStatus::Active->value]);
    $this->assertDatabaseHas('memberships', ['id' => $cited->id, 'status' => MembershipStatus::Disabled->value]);
    expect(User::query()->find($agent->user_id)->status)->toBe(UserStatus::Deleted)
        ->and(User::query()->find($agent->user_id)->first_name)->toBe('Ancien')
        ->and(User::query()->find($cited->user_id)->status)->toBe(UserStatus::Deleted)
        ->and(User::query()->find($multi->user_id)->status)->not->toBe(UserStatus::Deleted);

    // Données sans durée légale.
    $this->assertDatabaseMissing('member_pieces', ['organization_id' => $organization->id]);
    $this->assertDatabaseMissing('files', ['id' => $pieceFile->id]);
    $this->assertDatabaseMissing('concerns', ['id' => $concern->id]);
    $this->assertDatabaseMissing('concern_messages', ['concern_id' => $concern->id]);
    $this->assertDatabaseMissing('files', ['id' => $attachment->id]);
    Storage::disk('private')->assertMissing($attachment->path);
    $this->assertDatabaseHas('concerns', ['id' => $otherConcern->id]);
    $this->assertDatabaseMissing('message_logs', ['organization_id' => $organization->id]);
    $this->assertDatabaseMissing('settings', ['organization_id' => $organization->id]);
    $this->assertDatabaseMissing('role_permissions', ['organization_id' => $organization->id]);
    $this->assertDatabaseMissing('invoicing_providers', ['organization_id' => $organization->id]);
    expect(DB::table('notifications')->where('data->organization_id', $organization->id)->exists())->toBeFalse()
        ->and(DB::table('notifications')->where('data->organization_id', $other->id)->exists())->toBeTrue();

    expect(DB::table('audit_logs')->where('subject_id', $organization->id)->where('event', 'organization_data_purged')->exists())->toBeTrue()
        ->and(app(PurgeRetention::class)->execute(purgeToday())['purged_terminated_organizations'])->toBe(0);
});

it('RG-ORG-07 : rien ne part avant l\'échéance de suppression', function () {
    $organization = terminatedOrganization(purgeToday()->addDay());
    $agent = Membership::factory()->create(['organization_id' => $organization->id]);
    $member = Tenancy::bypass(static fn () => Member::factory()->active()->create(['organization_id' => $organization->id]), 'Test : dossier.');

    expect(app(PurgeRetention::class)->execute(purgeToday())['purged_terminated_organizations'])->toBe(0);
    $this->assertDatabaseHas('memberships', ['id' => $agent->id]);
    $this->assertDatabaseHas('members', ['id' => $member->id]);
});

it('PRD §7 : la simulation compte sans rien modifier', function () {
    $organization = terminatedOrganization(purgeToday()->subDay());
    $agent = Membership::factory()->create(['organization_id' => $organization->id]);
    $old = Tenancy::bypass(static fn () => radiatedMember($organization, 'Société Simulée', purgeToday()->subYears(11)), 'Test : dossier.');

    $stats = app(PurgeRetention::class)->execute(purgeToday(), dryRun: true);

    expect($stats['purged_terminated_organizations'])->toBe(1)
        ->and($stats['anonymized_members'])->toBe(1);
    $this->assertDatabaseHas('memberships', ['id' => $agent->id]);
    $this->assertDatabaseHas('members', ['id' => $old->id, 'company_name' => 'Société Simulée']);
    $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'data_deletion_due_at' => $organization->data_deletion_due_at]);
});

it('la commande retention:purge s\'exécute, en simulation comme pour de vrai', function () {
    $this->artisan('retention:purge --dry-run')->assertSuccessful();
    $this->artisan('retention:purge')->assertSuccessful();
});
