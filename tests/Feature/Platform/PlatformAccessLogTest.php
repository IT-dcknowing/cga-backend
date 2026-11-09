<?php

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Platform\Actions\RecordPlatformAccess;
use App\Domain\Platform\Models\PlatformAccessLog;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Enums\UserStatus;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

function createTestUserWithRole(Role $role, ?Organization $org = null): array
{
    $org = $org ?? Organization::factory()->create([
        'name' => 'CGA Test Access '.uniqid(),
        'type' => $role->organizationType(),
        'sigle' => 'CTA',
        'email' => 'cga-test-'.uniqid().'@example.ci',
        'phone_1' => '+22501020304',
        'address' => 'Abidjan',
        'city' => 'Abidjan',
    ]);

    $user = User::factory()->create();

    $membership = Membership::factory()->role($role)->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => MembershipStatus::Active,
    ]);

    return [$user, $membership, $org];
}

test('RG-SEC-04 : seul platform_admin peut consigner un acces support technique', function (): void {
    [$admin, $adminMem, $platformOrg] = createTestUserWithRole(Role::PlatformAdmin);
    [$cgaUser, $cgaMem, $cgaOrg] = createTestUserWithRole(Role::CgaManager);

    // Non autorisé avec un rôle CGA
    $response = inSpace($cgaMem)
        ->postJson('/api/v1/platform/access-logs', [
            'organization_id' => $cgaOrg->id,
            'reason' => 'Maintenance urgente de la base',
        ]);

    $response->assertStatus(403);

    // Autorisé avec platform_admin
    $responseAdmin = inSpace($adminMem)
        ->postJson('/api/v1/platform/access-logs', [
            'organization_id' => $cgaOrg->id,
            'reason' => 'Assistance technique sur la facture FNE',
            'route' => '/cga/invoices/123',
        ]);

    $responseAdmin->assertStatus(201)
        ->assertJsonPath('data.organization_id', $cgaOrg->id)
        ->assertJsonPath('data.reason', 'Assistance technique sur la facture FNE');

    $this->assertDatabaseHas('platform_access_logs', [
        'organization_id' => $cgaOrg->id,
        'admin_user_id' => $admin->id,
        'reason' => 'Assistance technique sur la facture FNE',
    ]);
});

test('RG-SEC-04 : un CGA peut consulter et exporter le journal des acces techniques a ses donnees', function (): void {
    [$admin, $adminMem, $platformOrg] = createTestUserWithRole(Role::PlatformAdmin);
    [$cgaUser, $cgaMem, $cgaOrg] = createTestUserWithRole(Role::CgaManager);
    [$otherUser, $otherMem, $otherOrg] = createTestUserWithRole(Role::CgaManager);

    $action = app(RecordPlatformAccess::class);
    $action->execute($admin, $cgaOrg, 'Diagnostic comptable', '/cga/members', now()->toImmutable());
    $action->execute($admin, $otherOrg, 'Autre diagnostic chez un confrère', '/cga/reports', now()->toImmutable());

    // Le CGA consulte ses logs
    $response = inSpace($cgaMem)
        ->getJson('/api/v1/cga/support-access-logs');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reason', 'Diagnostic comptable');

    // Le CGA exporte son journal en CSV
    $exportResponse = inSpace($cgaMem)
        ->get('/api/v1/cga/support-access-logs/export');

    $exportResponse->assertStatus(200);
    expect($exportResponse->headers->get('content-type'))->toContain('text/csv');
    expect($exportResponse->streamedContent())->toContain('Diagnostic comptable');
    expect($exportResponse->streamedContent())->not->toContain('Autre diagnostic chez un confrère');
});
