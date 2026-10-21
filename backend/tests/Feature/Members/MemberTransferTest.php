<?php

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\MemberTransfer;
use App\Domain\Organizations\Enums\SubscriptionStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
 * Réimport depuis un autre CGA (06-IMPLEMENTATION-PLAN étape 2.5, F-ADH-10, RG-ADH-15) : identité,
 * NCC et coordonnées repris avec l'accord écrit de l'adhérent, jamais l'historique de gestion.
 */

beforeEach(function () {
    Storage::fake();
});

function transferManager(Role $role = Role::CgaManager): Membership
{
    return Membership::factory()->role($role)->create();
}

/**
 * Dossier radié d'un autre CGA abonné, avec son historique de gestion.
 */
function formerMember(array $overrides = []): Member
{
    return Member::factory()->active()->create([
        'organization_id' => Organization::factory()->create(['name' => 'CGA DE L\'ANCIEN CENTRE'])->id,
        'status' => MemberStatus::Radiated,
        'ncc' => '1234567R',
        'company_name' => 'ETS ANCIEN ADHÉRENT',
        'owner_last_name' => 'Yao',
        'owner_id_document_number' => 'CI-0042',
        'commune_id' => null,
        'regime' => 'RME',
        'fees_amount' => 99_000,
        ...$overrides,
    ]);
}

function reimport(Membership $agent, array $overrides = []): TestResponse
{
    return inSpace($agent)->post('/api/v1/cga/members/transfers', [
        'ncc' => '1234567 r',
        'consent' => UploadedFile::fake()->createWithContent('accord.pdf', "%PDF-1.4\n%%EOF"),
        ...$overrides,
    ], ['Accept' => 'application/json']);
}

it('RG-ADH-15 reprend l\'identité d\'un adhérent d\'un autre CGA abonné, avec son accord écrit, sans son historique', function () {
    $manager = transferManager();
    $former = formerMember();

    $response = reimport($manager)
        ->assertCreated()
        ->assertJsonPath('data.identity.ncc', '1234567R')
        ->assertJsonPath('data.identity.company_name', 'ETS ANCIEN ADHÉRENT')
        ->assertJsonPath('data.identity.entity_type', 'company')
        ->assertJsonPath('data.identity.owner_last_name', 'Yao')
        ->assertJsonPath('data.identity.owner_id_document_number', 'CI-0042')
        ->assertJsonPath('data.identity.phone', $former->phone)
        ->assertJsonMissingPath('data.identity.regime')
        ->assertJsonMissingPath('data.identity.fees_amount')
        ->assertJsonMissingPath('data.identity.membership_number');
    expect($response->getContent())->not->toContain('ANCIEN CENTRE');

    $transfer = MemberTransfer::query()->findOrFail($response->json('data.transfer_id'));
    expect($transfer->from_member_id)->toBe($former->id)
        ->and($transfer->to_organization_id)->toBe($manager->organization_id)
        ->and($transfer->requested_by)->toBe($manager->user_id)
        ->and(DB::table('files')->where('id', $transfer->consent_file_id)->value('original_name'))->toBe('accord.pdf');

    $member = inSpace($manager)->postJson('/api/v1/cga/members', [
        ...array_filter($response->json('data.identity'), static fn (mixed $value): bool => $value !== null),
        'portfolio' => 'cga', 'regime' => 'TEE', 'main_activity' => 'Commerce', 'transfer_id' => $transfer->id,
    ])->assertCreated()->assertJsonPath('data.company_name', 'ETS ANCIEN ADHÉRENT')->json('data.id');

    expect($transfer->fresh()?->to_member_id)->toBe($member);
});

it('relie le réimport au dossier de son NCC seulement, une seule fois', function () {
    $manager = transferManager();
    formerMember();
    $transfer = reimport($manager)->json('data.transfer_id');
    $form = ['portfolio' => 'cabinet', 'entity_type' => 'company', 'company_name' => 'Autre', 'legal_form' => 'SARL', 'regime' => 'TEE', 'main_activity' => 'Commerce', 'phone' => '0707070707'];

    inSpace($manager)->postJson('/api/v1/cga/members', [...$form, 'ncc' => '7654321A', 'transfer_id' => $transfer])->assertUnprocessable()
        ->assertJsonPath('errors.transfer_id.0', 'Le NCC saisi n\'est pas celui du dossier réimporté.');
    inSpace($manager)->postJson('/api/v1/cga/members', [...$form, 'ncc' => '7654321A', 'transfer_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ'])->assertUnprocessable()
        ->assertJsonPath('errors.transfer_id.0', 'Ce réimport est inconnu ou a déjà servi à une inscription.');
    inSpace(transferManager())->postJson('/api/v1/cga/members', [...$form, 'ncc' => '1234567R', 'transfer_id' => $transfer])->assertUnprocessable()->assertJsonValidationErrors('transfer_id');
    expect(MemberTransfer::query()->findOrFail($transfer)->to_member_id)->toBeNull();
});

it('refuse sans dossier dans un autre CGA abonné, sans accord, pour un NCC déjà ouvert ici, et sans le droit d\'inscrire', function () {
    $manager = transferManager();

    reimport($manager)->assertNotFound()->assertJsonPath('code', 'transfer_source_not_found');
    formerMember(['organization_id' => Organization::factory()->create(['subscription_status' => SubscriptionStatus::Terminated])->id]);
    reimport($manager)->assertNotFound()->assertJsonPath('code', 'transfer_source_not_found');
    Member::factory()->create(['ncc' => '1234567R']);
    reimport($manager)->assertNotFound();

    formerMember();
    reimport($manager, ['consent' => null, 'ncc' => '12'])->assertUnprocessable()->assertJsonValidationErrors(['consent', 'ncc']);
    Member::factory()->create(['organization_id' => $manager->organization_id, 'ncc' => '1234567R']);
    reimport($manager)->assertStatus(409)->assertJsonPath('code', 'ncc_already_registered');
    reimport(transferManager(Role::CgaBillingOfficer))->assertForbidden();
    expect(MemberTransfer::query()->count())->toBe(0);
});
