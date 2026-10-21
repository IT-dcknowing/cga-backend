<?php

use App\Domain\Members\Models\BusinessReferrer;
use App\Domain\Members\Models\Member;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\TenantContext;

/*
 * Apporteurs d'affaires du centre (06-IMPLEMENTATION-PLAN étape 2.14, RG-PTF-06) : liste, création avec
 * libellé « Accessoire n » proposé, modification, désactivation, suppression d'un apporteur jamais
 * rattaché ; réservés au droit « Voir les apporteurs ».
 */

it('RG-PTF-06 crée les apporteurs avec un libellé « Accessoire n » proposé, et les liste avec leurs dossiers', function () {
    $manager = Membership::factory()->role(Role::CgaManager)->create();

    inSpace($manager)->postJson('/api/v1/cga/referrers', ['name' => 'Ibrahim Traoré', 'phone' => '07 01 02 03 04', 'terms' => '5 % des honoraires de la première année'])
        ->assertCreated()
        ->assertJsonPath('data.label', 'Accessoire 1')
        ->assertJsonPath('data.phone', '+2250701020304')
        ->assertJsonPath('data.active', true)
        ->assertJsonPath('data.can.delete', true);
    $second = inSpace($manager)->postJson('/api/v1/cga/referrers', ['name' => 'Awa Diallo'])->assertCreated()->assertJsonPath('data.label', 'Accessoire 2')->json('data.id');
    inSpace($manager)->postJson('/api/v1/cga/referrers', ['name' => 'Autre', 'label' => 'Accessoire 2'])->assertUnprocessable()->assertJsonValidationErrors('label');
    Member::factory()->active()->create(['organization_id' => $manager->organization_id, 'referrer_id' => $second]);

    inSpace($manager)->getJson('/api/v1/cga/referrers')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.1.label', 'Accessoire 2')
        ->assertJsonPath('data.1.members_count', 1)
        ->assertJsonPath('data.1.can.delete', false);
});

it('modifie et désactive un apporteur ; ne supprime que celui qui n\'a jamais été rattaché', function () {
    $manager = Membership::factory()->role(Role::CgaManager)->create();
    $used = BusinessReferrer::factory()->create(['organization_id' => $manager->organization_id]);
    $unused = BusinessReferrer::factory()->create(['organization_id' => $manager->organization_id]);
    Member::factory()->active()->create(['organization_id' => $manager->organization_id, 'referrer_id' => $used->id]);

    inSpace($manager)->putJson("/api/v1/cga/referrers/{$used->id}", ['name' => 'Nom corrigé', 'active' => false])
        ->assertOk()
        ->assertJsonPath('data.name', 'Nom corrigé')
        ->assertJsonPath('data.label', $used->label)
        ->assertJsonPath('data.active', false);
    inSpace($manager)->deleteJson("/api/v1/cga/referrers/{$used->id}")->assertStatus(409)->assertJsonPath('code', 'referrer_in_use');
    inSpace($manager)->deleteJson("/api/v1/cga/referrers/{$unused->id}")->assertNoContent();

    expect(app(TenantContext::class)->runAs($manager, fn () => BusinessReferrer::query()->pluck('id')->all()))->toBe([$used->id]);
});

it('réserve les apporteurs au droit de les voir, leur gestion à ceux qui paramètrent le centre', function () {
    $manager = Membership::factory()->role(Role::CgaManager)->create();
    $referrer = BusinessReferrer::factory()->create(['organization_id' => $manager->organization_id]);
    $expert = Membership::factory()->role(Role::CgaAccountantExpert)->create(['organization_id' => $manager->organization_id]);
    $accountant = Membership::factory()->role(Role::CgaAccountant)->create(['organization_id' => $manager->organization_id]);

    inSpace($expert)->getJson('/api/v1/cga/referrers')->assertOk()->assertJsonPath('data.0.can.update', false);
    inSpace($expert)->postJson('/api/v1/cga/referrers', ['name' => 'Refusé'])->assertForbidden();
    inSpace($expert)->deleteJson("/api/v1/cga/referrers/{$referrer->id}")->assertForbidden();
    inSpace($accountant)->getJson('/api/v1/cga/referrers')->assertForbidden();
    inSpace($manager)->postJson('/api/v1/cga/referrers', ['name' => '', 'email' => 'pas-un-email'])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email']);
    inSpace(Membership::factory()->role(Role::CgaManager)->create())->putJson("/api/v1/cga/referrers/{$referrer->id}", ['name' => 'X'])->assertNotFound();
    inSpace(Membership::factory()->role(Role::CncgaPresident)->create())->getJson('/api/v1/cga/referrers')->assertForbidden();
});
