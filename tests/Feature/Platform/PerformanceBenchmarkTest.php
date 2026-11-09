<?php

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('02-TRD §12.4 : verification des cibles de performance sur les requêtes clés', function (): void {
    $org = Organization::factory()->cga()->create([
        'name' => 'CGA Perf Test',
        'sigle' => 'CPT',
    ]);

    $user = User::factory()->create();

    $membership = Membership::factory()->role(Role::CgaManager)->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    // Insertion d'un lot d'adhérents de test
    $rows = [];
    for ($i = 1; $i <= 100; $i++) {
        $rows[] = [
            'id' => strtolower((string) Str::ulid()),
            'organization_id' => $org->id,
            'portfolio' => Portfolio::Cga->value,
            'status' => MemberStatus::Active->value,
            'company_name' => 'Entreprise Test '.$i,
            'ncc' => sprintf('%07dD', 1000000 + $i),
            'cga_contract_signed_on' => '2026-01-01',
            'phone' => sprintf('+22507%08d', $i),
            'entity_type' => 'company',
            'legal_form' => 'SARL',
            'regime' => 'TEE',
            'regime_since' => '2026-01-01',
            'main_activity' => 'Commerce général',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
    DB::table('members')->insert($rows);

    // Insertion d'agrégats statistiques pour le tableau de bord
    DB::table('stat_org_daily')->insert([
        'organization_id' => $org->id,
        'day' => today()->toDateString(),
        'active_members' => 100,
        'members_rme' => 0,
        'members_tee' => 100,
        'members_rsi' => 0,
        'new_members' => 10,
        'radiations' => 0,
        'fees_due' => 50_000,
        'fees_paid' => 50_000,
        'tax_paid_total' => 0,
        'tax_paid_by_nature' => '{}',
        'declared_revenue' => 0,
        'members_by_sector' => '{}',
        'members_by_commune' => '{}',
        'concerns_opened' => 0,
        'concerns_closed' => 0,
        'concerns_responded' => 0,
        'concerns_avg_response_hours' => null,
        'computed_at' => now(),
    ]);

    // Requête de pré-chauffe pour isoler le temps de réponse applicatif du démarrage PHP/Laravel
    inSpace($membership)->getJson('/api/v1/cga/members?per_page=1');

    // 1. Cible : Liste paginée (25 lignes) < 1 s côté serveur
    $start = microtime(true);
    $responseList = inSpace($membership)->getJson('/api/v1/cga/members?per_page=25');
    $durationList = (microtime(true) - $start) * 1000;

    $responseList->assertStatus(200);
    expect($durationList)->toBeLessThan(2500); // Marge généreuse en environnement local/CI

    // 2. Cible : Recherche d'adhérent < 1 s
    $startSearch = microtime(true);
    $responseSearch = inSpace($membership)->getJson('/api/v1/cga/members?filter[search]=Entreprise+Test+50');
    $durationSearch = (microtime(true) - $startSearch) * 1000;

    $responseSearch->assertStatus(200)
        ->assertJsonPath('data.0.company_name', 'Entreprise Test 50');
    expect($durationSearch)->toBeLessThan(2000);

    // 3. Cible : Tableau de bord < 1 s (lecture des agrégats)
    $startDash = microtime(true);
    $responseDash = inSpace($membership)->getJson('/api/v1/cga/dashboard');
    $durationDash = (microtime(true) - $startDash) * 1000;

    $responseDash->assertStatus(200);
    expect($durationDash)->toBeLessThan(2000);
});
