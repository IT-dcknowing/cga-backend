<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Payments\Fne\FakeFneCertifier;
use App\Domain\Payments\Models\InvoicingProvider;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
 * Paramétrage « Facturation FNE » du centre (06-IMPLEMENTATION-PLAN étape 3.13, F-ADM-07, RG-FNE-03,
 * 02-TRD §10 bis) : mode manuel par défaut, mode API avec une adresse HTTPS d'un hôte autorisé et des
 * identifiants chiffrés jamais renvoyés, expression de contrôle du n° FNE, bouton « Tester ».
 */

beforeEach(function () {
    config(['integrations.fne.allowed_hosts' => ['fne.dgi.test'], 'integrations.fne.drivers.dgi_api' => FakeFneCertifier::class]);
});

/**
 * @param  array<string, mixed>  $payload
 */
function saveProvider(Membership $agent, array $payload): TestResponse
{
    return invoiceSpace($agent)->putJson('/api/v1/cga/invoicing-provider', ['mode' => 'manual', 'active' => true, ...$payload]);
}

it('RG-FNE-03 propose le mode manuel par défaut ; le mode API exige une adresse autorisée et des identifiants, chiffrés et jamais renvoyés', function () {
    $manager = documentAgent();

    invoiceSpace($manager)->getJson('/api/v1/cga/invoicing-provider')->assertOk()
        ->assertJsonPath('data.mode', 'manual')
        ->assertJsonPath('data.credentials_hint', null)
        ->assertJsonPath('data.modes.1.value', 'dgi_api')
        ->assertJsonPath('data.modes.1.available', true)
        ->assertJsonPath('data.modes.2.value', 'sell_flow')
        ->assertJsonPath('data.modes.2.available', false)
        ->assertJsonPath('data.can.test', false);

    saveProvider($manager, ['mode' => 'dgi_api', 'base_url' => 'https://fne.dgi.test/api'])->assertUnprocessable()->assertJsonValidationErrors('credentials');
    saveProvider($manager, ['mode' => 'dgi_api', 'base_url' => 'http://fne.dgi.test/api', 'credentials' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('base_url');
    saveProvider($manager, ['mode' => 'dgi_api', 'base_url' => 'https://pirate.example/api', 'credentials' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('base_url');
    saveProvider($manager, ['mode' => 'dgi_api', 'credentials' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('base_url');
    saveProvider($manager, ['mode' => 'sell_flow'])->assertUnprocessable()->assertJsonValidationErrors('mode');
    saveProvider($manager, ['fne_number_pattern' => '^FNE-(\d+'])->assertUnprocessable()->assertJsonValidationErrors('fne_number_pattern');
    saveProvider($manager, ['fne_number_pattern' => ['^FNE']])->assertUnprocessable()->assertJsonValidationErrors(['fne_number_pattern' => __('validation.pattern')]);

    saveProvider($manager, ['mode' => 'dgi_api', 'base_url' => 'https://fne.dgi.test/api', 'credentials' => 'jeton-secret-1234', 'fne_number_pattern' => '^FNE-\d+$'])->assertOk()
        ->assertJsonPath('data.mode', 'dgi_api')
        ->assertJsonPath('data.base_url', 'https://fne.dgi.test/api')
        ->assertJsonPath('data.credentials_hint', '••••1234')
        ->assertJsonMissingPath('data.credentials')
        ->assertJsonPath('data.can.test', true);

    $raw = (string) DB::table('invoicing_providers')->where('organization_id', $manager->organization_id)->value('credentials');
    expect($raw)->not->toContain('jeton-secret-1234')
        ->and(json_encode(AuditLog::query()->where('subject_type', (new InvoicingProvider)->getMorphClass())->pluck('changes')))->not->toContain('jeton-secret-1234');

    saveProvider($manager, ['mode' => 'dgi_api', 'base_url' => 'https://fne.dgi.test/api', 'credentials' => ''])->assertOk()->assertJsonPath('data.credentials_hint', '••••1234');
    expect(Tenancy::bypass(fn () => InvoicingProvider::query()->where('organization_id', $manager->organization_id)->sole()->credentials, 'Test : identifiants gardés.'))->toBe('jeton-secret-1234');
});

it('teste la connexion au service et garde le résultat ; le mode manuel n\'a rien à tester', function () {
    $manager = documentAgent();
    invoiceSpace($manager)->postJson('/api/v1/cga/invoicing-provider/test')->assertStatus(409)->assertJsonPath('code', 'invoicing_test_unavailable');
    saveProvider($manager, [])->assertOk();
    invoiceSpace($manager)->postJson('/api/v1/cga/invoicing-provider/test')->assertStatus(409)->assertJsonPath('code', 'invoicing_test_unavailable');

    saveProvider($manager, ['mode' => 'dgi_api', 'base_url' => 'https://fne.dgi.test/api', 'credentials' => 'jeton'])->assertOk();
    app()->instance(FakeFneCertifier::class, new FakeFneCertifier([FakeFneCertifier::ACCEPT]));
    invoiceSpace($manager)->postJson('/api/v1/cga/invoicing-provider/test')->assertOk()->assertJsonPath('data.last_test_ok', true);

    app()->instance(FakeFneCertifier::class, new FakeFneCertifier([FakeFneCertifier::UNAVAILABLE]));
    invoiceSpace($manager)->postJson('/api/v1/cga/invoicing-provider/test')->assertOk()->assertJsonPath('data.last_test_ok', false);

    saveProvider($manager, ['mode' => 'dgi_api', 'base_url' => 'https://fne.dgi.test/v2', 'credentials' => ''])->assertOk()->assertJsonPath('data.last_test_at', null);
});

it('se lit par le gérant et l\'expert-comptable, se modifie et se teste par le gérant seul', function () {
    $manager = documentAgent();
    $expert = documentAgent(Role::CgaAccountantExpert, $manager->organization_id);

    invoiceSpace($expert)->getJson('/api/v1/cga/invoicing-provider')->assertOk()->assertJsonPath('data.can.update', false);
    saveProvider($expert, [])->assertForbidden();
    invoiceSpace($expert)->postJson('/api/v1/cga/invoicing-provider/test')->assertForbidden();
    foreach ([Role::CgaAccountant, Role::CgaBillingOfficer] as $role) {
        invoiceSpace(documentAgent($role, $manager->organization_id))->getJson('/api/v1/cga/invoicing-provider')->assertForbidden();
    }
    invoiceSpace(Membership::factory()->role(Role::CncgaPresident)->create())->getJson('/api/v1/cga/invoicing-provider')->assertForbidden();
});
