<?php

use App\Domain\Organizations\Models\Organization;
use App\Domain\Referentials\Models\TaxOffice;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Fiche d'identité du CNCGA (06-IMPLEMENTATION-PLAN étape 6.9) : le CNCGA émet les reçus de cotisation,
 * signés par la Présidente (PRD F-DOC-01), et les factures de cotisation certifiées FNE ; il complète sa
 * fiche comme un CGA (F-ORG-03, RG-ORG-04), sans agrément ni expert-comptable référent.
 */

beforeEach(function () {
    Storage::fake();
    $this->cncga = Organization::factory()->cncga()->create();
    $this->president = Membership::factory()->role(Role::CncgaPresident)->create(['organization_id' => $this->cncga->id]);
});

function networkIdentity(): array
{
    $taxOffice = TaxOffice::factory()->create();

    return [
        'legal_form' => 'Association', 'share_capital' => 0, 'ncc' => 'CI-7654321Z', 'rccm' => 'CI-ABJ-2010-W-0001',
        'regional_directorate_id' => $taxOffice->regional_directorate_id, 'tax_office_id' => $taxOffice->id,
        'address' => 'Cocody, Riviera 3', 'city' => 'Abidjan', 'phone_1' => '0707070708', 'email' => 'secretariat@cncga.ci',
        'manager_name' => 'Aminata Présidente', 'signatory_name' => 'Aminata Présidente', 'signatory_title' => 'La Présidente',
        'numbering_abbreviation' => 'CNCGA',
    ];
}

function networkImage(string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
}

it('RG-ORG-04 · la Présidente complète la fiche du CNCGA : ni agrément ni expert-comptable exigés', function () {
    inSpace($this->president)->getJson('/api/v1/network/profile')
        ->assertOk()->assertJsonPath('data.id', $this->cncga->id)->assertJsonPath('data.can.update', true)
        ->assertJsonPath('data.profile_completed_at', null);

    inSpace($this->president)->putJson('/api/v1/network/profile', networkIdentity())
        ->assertOk()->assertJsonPath('data.missing_fields', ['logo_file_id', 'signature_file_id', 'stamp_file_id']);
    inSpace($this->president)->postJson('/api/v1/network/profile/images/logo', ['image' => networkImage('logo.png')])->assertOk();
    inSpace($this->president)->postJson('/api/v1/network/profile/images/signature', ['image' => networkImage('signature.png')])->assertOk();
    $response = inSpace($this->president)->postJson('/api/v1/network/profile/images/stamp', ['image' => networkImage('cachet.png')])
        ->assertOk()->assertJsonPath('data.missing_fields', []);

    expect($response->json('data.profile_completed_at'))->not->toBeNull()
        ->and($this->cncga->refresh()->missingProfileFields())->toBe([]);
});

it('refuse les champs d\'agrément et d\'expert-comptable sur la fiche du CNCGA (422)', function () {
    inSpace($this->president)->putJson('/api/v1/network/profile', [...networkIdentity(), 'agreement_number' => 'AGR-1', 'agreement_date' => '2020-01-01', 'expert_name' => 'Cabinet'])
        ->assertUnprocessable()->assertJsonValidationErrors(['agreement_number', 'agreement_date', 'expert_name']);
    inSpace($this->president)->postJson('/api/v1/network/profile/images/inconnu', ['image' => networkImage('x.png')])->assertNotFound();
});

it('droits : Présidente et gestionnaire du réseau ; refusée au chargé des cotisations, au bureau et à un CGA', function () {
    $duesOfficer = Membership::factory()->role(Role::CncgaDues)->create(['organization_id' => $this->cncga->id]);
    $manager = Membership::factory()->role(Role::CgaManager)->create();

    $networkManager = Membership::factory()->role(Role::CncgaManager)->create(['organization_id' => $this->cncga->id]);
    $board = Membership::factory()->role(Role::CncgaViewer)->create(['organization_id' => $this->cncga->id]);
    inSpace($networkManager)->getJson('/api/v1/network/profile')->assertOk()->assertJsonPath('data.can.update', true);
    inSpace($duesOfficer)->getJson('/api/v1/network/profile')->assertForbidden();
    inSpace($board)->getJson('/api/v1/network/profile')->assertForbidden();
    inSpace($duesOfficer)->putJson('/api/v1/network/profile', networkIdentity())->assertForbidden();
    inSpace($duesOfficer)->postJson('/api/v1/network/profile/images/logo', ['image' => networkImage('logo.png')])->assertForbidden();
    inSpace($manager)->getJson('/api/v1/network/profile')->assertForbidden();
    inSpace($manager)->putJson('/api/v1/network/profile', networkIdentity())->assertForbidden();
    inSpace($this->president)->getJson('/api/v1/cga/profile')->assertForbidden();
    inSpace($this->president)->putJson('/api/v1/cga/profile', networkIdentity())->assertForbidden();
});
