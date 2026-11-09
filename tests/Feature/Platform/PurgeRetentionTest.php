<?php

use App\Domain\Concerns\Models\Concern;
use App\Domain\Documents\Models\File;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\MemberPiece;
use App\Domain\Notifications\Models\MessageLog;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Enums\SubscriptionStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Platform\Actions\PurgeRetention;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

test('RG-ORG-07 et PRD §7 : purge et anonymisation des donnees perimees selon les regles legales', function (): void {
    Storage::fake('private');

    $org = Organization::factory()->create([
        'name' => 'CGA Retention Test',
        'sigle' => 'CRT',
        'email' => 'crt@example.ci',
        'phone_1' => '+22501020304',
        'address' => 'Abidjan',
        'city' => 'Abidjan',
        'subscription_status' => SubscriptionStatus::Active,
    ]);

    $now = CarbonImmutable::parse('2026-11-05 12:00:00');

    $radiatedOld = null;
    $activeMember = null;

    Tenancy::bypass(function () use ($org, $now, &$radiatedOld, &$activeMember): void {
        // 1. Adhérent radié depuis 11 ans (> 10 ans)
        $radiatedOld = Member::factory()->create([
            'organization_id' => $org->id,
            'company_name' => 'Société Alpha Ancienne',
            'owner_last_name' => 'Kouassi',
            'owner_first_names' => 'Jean',
            'status' => MemberStatus::Radiated,
            'cga_contract_signed_on' => $now->subYears(12)->toDateString(),
            'created_at' => $now->subYears(12),
            'updated_at' => $now->subYears(11),
        ]);

        $file = new File;
        $file->organization_id = $org->id;
        $file->disk = 'private';
        $file->path = 'orgs/test/piece.pdf';
        $file->original_name = 'piece.pdf';
        $file->mime_type = 'application/pdf';
        $file->size_bytes = 1024;
        $file->sha256 = hash('sha256', 'piece content');
        $file->save();

        DB::table('member_pieces')->insert([
            'id' => strtolower((string) Str::ulid()),
            'member_id' => $radiatedOld->id,
            'organization_id' => $org->id,
            'piece_type' => 'id_document',
            'status' => 'received',
            'file_id' => $file->id,
            'created_at' => $now->subYears(11),
            'updated_at' => $now->subYears(11),
        ]);

        // Adhérent actif (ne doit pas être touché)
        $activeMember = Member::factory()->active()->create([
            'organization_id' => $org->id,
            'company_name' => 'Société Active Présente',
            'owner_last_name' => 'Touré',
            'owner_first_names' => 'Moussa',
            'status' => MemberStatus::Active,
            'created_at' => $now->subMonths(2),
            'updated_at' => $now->subMonths(2),
        ]);

        // 2. Préoccupation de plus de 5 ans
        Concern::factory()->create([
            'organization_id' => $org->id,
            'member_id' => $activeMember->id,
            'created_at' => $now->subYears(6),
            'updated_at' => $now->subYears(6),
        ]);

        // 3. Message de notification de plus de 2 ans
        DB::table('message_logs')->insert([
            'organization_id' => $org->id,
            'channel' => 'sms',
            'recipient' => '+22501020304',
            'status' => 'sent',
            'created_at' => $now->subYears(3),
        ]);
    }, 'Données de test pour purge rétention');

    // 4. Exécuter l'action de purge
    $action = app(PurgeRetention::class);
    $stats = $action->execute($now);

    expect($stats['anonymized_members'])->toBe(1);
    expect($stats['purged_concerns'])->toBe(1);
    expect($stats['purged_messages'])->toBe(1);

    // Vérifier l'anonymisation
    Tenancy::bypass(fn () => $radiatedOld->refresh(), 'Test vérification anonymisation');
    expect($radiatedOld->company_name)->toBe('[Anonymisé]');
    expect($radiatedOld->phone)->toBe('+22500000000');
    expect($radiatedOld->email)->toBeNull();
    $this->assertDatabaseMissing('member_pieces', ['member_id' => $radiatedOld->id]);

    // Vérifier que l'actif est intact
    Tenancy::bypass(fn () => $activeMember->refresh(), 'Test vérification actif');
    expect($activeMember->company_name)->toBe('Société Active Présente');
    expect($activeMember->phone)->not->toBe('+22500000000');
});

test('RG-ORG-07 : purge des donnees d un CGA résilié apres 6 mois', function (): void {
    $now = CarbonImmutable::parse('2026-11-05 12:00:00');

    $terminatedOrg = Organization::factory()->create([
        'name' => 'CGA Résilié Ancien',
        'sigle' => 'CRA',
        'email' => 'cra@example.ci',
        'phone_1' => '+22501020304',
        'address' => 'Abidjan',
        'city' => 'Abidjan',
    ]);
    $terminatedOrg->subscription_status = SubscriptionStatus::Terminated;
    $terminatedOrg->data_deletion_due_at = $now->subDays(10); // Échu il y a 10 jours
    $terminatedOrg->save();

    Tenancy::bypass(function () use ($terminatedOrg, $now): void {
        Member::factory()->create([
            'organization_id' => $terminatedOrg->id,
            'company_name' => 'Entreprise du CGA résilié',
            'status' => MemberStatus::Radiated,
            'cga_contract_signed_on' => $now->subMonths(10)->toDateString(),
            'created_at' => $now->subMonths(8),
            'updated_at' => $now->subMonths(8),
        ]);
    }, 'Données de test pour CGA résilié');

    $action = app(PurgeRetention::class);
    $stats = $action->execute($now);

    expect($stats['purged_terminated_cga_records'])->toBe(1);

    // Les adhérents privés sont purgés
    $this->assertDatabaseMissing('members', ['organization_id' => $terminatedOrg->id]);

    // Mais l'organisation elle-même reste en base pour les cotisations/statistiques CNCGA (RG-ORG-07)
    $this->assertDatabaseHas('organizations', ['id' => $terminatedOrg->id]);
});

test('Commande console retention:purge s exécute avec succès', function (): void {
    $this->artisan('retention:purge --dry-run')
        ->assertSuccessful();
});
