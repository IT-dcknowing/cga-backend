<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Documents\Models\File;
use App\Domain\Members\Enums\EntityType;
use App\Domain\Members\Enums\PieceStatus;
use App\Domain\Members\Enums\PieceType;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\MemberRevenue;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Payments\Models\MemberCharge;
use App\Domain\Referentials\Models\Sector;
use App\Domain\Referentials\Models\TaxOffice;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Testing\TestResponse;
use Tests\Support\Concurrency;

/*
 * Validation d'une adhésion (06-IMPLEMENTATION-PLAN étape 2.3, RG-ADH-02, 03) : contrôles, numéro
 * CGA Connect, pièces attendues, abréviation figée.
 */

function centreManager(string $abbreviation = 'DCK'): Membership
{
    return Membership::factory()->role(Role::CgaManager)->create([
        'organization_id' => Organization::factory()->create(['numbering_abbreviation' => $abbreviation, 'abbreviation_locked_at' => null])->id,
    ]);
}

/**
 * Brouillon complet, prêt à valider.
 */
function readyDraft(Membership $manager, array $overrides = []): Member
{
    $member = Member::factory()->create([
        'organization_id' => $manager->organization_id,
        'tax_office_id' => TaxOffice::factory()->create()->id,
        'sector_id' => Sector::factory()->create()->id,
        'address' => 'Cocody, II Plateaux',
        'signatory_name' => 'Koffi Kouassi',
        'signatory_title' => 'Gérant',
        'cga_contract_signed_on' => '2026-03-01',
        'cga_contract_confirmed_by' => $manager->user_id,
        ...$overrides,
    ]);
    app(TenantContext::class)->runAs($manager, function () use ($member): void {
        $revenue = new MemberRevenue;
        $revenue->member_id = $member->id;
        $revenue->year = 2025;
        $revenue->amount = 12_000_000;
        $revenue->setAttribute('kind', 'actual');
        $revenue->setAttribute('source', 'entry');
        $revenue->save();
    });

    return $member;
}

function validateMember(Membership $agent, Member $member, bool $confirmed = true): TestResponse
{
    return inSpace($agent)->postJson("/api/v1/cga/members/{$member->id}/validate", ['exclusivity_confirmed' => $confirmed]);
}

it('RG-ADH-02 liste ce qui manque pour valider, sur la fiche comme au refus', function () {
    $manager = centreManager();
    $draft = Member::factory()->create(['organization_id' => $manager->organization_id, 'entity_type' => EntityType::Individual]);
    $expected = ['tax_office_id', 'sector_id', 'address', 'signatory_name', 'signatory_title', 'owner_last_name', 'owner_first_names',
        'owner_nationality', 'owner_id_document_type', 'owner_id_document_number', 'reference_revenue', 'cga_contract_signed_on', 'cga_contract_confirmed'];

    inSpace($manager)->getJson("/api/v1/cga/members/{$draft->id}")->assertJsonPath('data.missing_for_validation', $expected)->assertJsonPath('data.can.validate', true);
    validateMember($manager, $draft, false)
        ->assertStatus(409)
        ->assertJsonPath('code', 'membership_incomplete')
        ->assertJsonPath('details.missing', [...$expected, 'exclusivity_confirmed']);
});

it('RG-ADH-03 valide un dossier du portefeuille CGA : numéro AAAA-ABR-NNNN, pièces attendues, abréviation figée', function () {
    $manager = centreManager();
    $member = readyDraft($manager);

    validateMember($manager, $member)
        ->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.membership_number', '2026-DCK-0001')
        ->assertJsonPath('data.adhesion_date', '2026-03-01')
        ->assertJsonPath('data.pieces_status', 'pending')
        ->assertJsonPath('data.missing_for_validation', [])
        ->assertJsonPath('data.can.validate', false);

    $pieces = app(TenantContext::class)->runAs($manager, fn () => $member->pieces()->pluck('status', 'piece_type')->map->value->all());
    expect($pieces)->toEqualCanonicalizing(['rccm' => 'expected', 'dfe' => 'expected', 'id_document' => 'expected', 'location_plan' => 'expected', 'statutes' => 'expected', 'signed_cga_contract' => 'expected'])
        ->and(Organization::query()->findOrFail($manager->organization_id)->abbreviation_locked_at)->not->toBeNull()
        ->and(AuditLog::query()->where('event', 'membership_validated')->where('subject_id', $member->id)->value('context'))
        ->toBe(['number' => '2026-DCK-0001', 'exclusivity_confirmed' => true]);
});

it('RG-ADH-03 continue la séquence d\'une année à l\'autre, sans remise à zéro', function () {
    $manager = centreManager();
    validateMember($manager, readyDraft($manager))->assertJsonPath('data.membership_number', '2026-DCK-0001');

    validateMember($manager, readyDraft($manager, ['cga_contract_signed_on' => '2027-01-05']))->assertJsonPath('data.membership_number', '2027-DCK-0002');
});

it('valide un dossier Cabinet sans contrat CGA : numéro client CAB-ABR-NNNNN, sans statuts pour une entreprise individuelle', function () {
    $manager = centreManager();
    $member = readyDraft($manager, ['portfolio' => 'cabinet', 'regime' => 'RSI', 'cga_contract_signed_on' => null, 'cga_contract_confirmed_by' => null,
        'entity_type' => EntityType::Individual, 'owner_last_name' => 'Kouadio', 'owner_first_names' => 'Jean', 'owner_nationality' => 'Ivoirienne',
        'owner_id_document_type' => 'CNI', 'owner_id_document_number' => 'C001']);

    validateMember($manager, $member, false)
        ->assertOk()
        ->assertJsonPath('data.client_number', 'CAB-DCK-00001')
        ->assertJsonPath('data.membership_number', null);

    expect(app(TenantContext::class)->runAs($manager, fn () => $member->pieces()->pluck('piece_type')->map->value->sort()->values()->all()))
        ->toBe(['dfe', 'id_document', 'location_plan', 'rccm']);
});

it('reçoit d\'office le contrat CGA signé déjà joint', function () {
    $manager = centreManager();
    $file = new File;
    $file->forceFill(['organization_id' => $manager->organization_id, 'disk' => 'private', 'path' => 'orgs/x/contracts/2026/c.pdf', 'original_name' => 'contrat.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'sha256' => str_repeat('a', 64)])->save();
    $member = readyDraft($manager, ['cga_contract_file_id' => $file->id]);

    validateMember($manager, $member)->assertOk();

    $contract = app(TenantContext::class)->runAs($manager, fn () => $member->pieces()->where('piece_type', PieceType::SignedCgaContract)->sole());
    expect($contract->status)->toBe(PieceStatus::Received)->and($contract->file_id)->toBe($file->id);
});

it('suit les formats de numérotation paramétrés', function () {
    $manager = centreManager('ABC');
    app(SettingsService::class)->set('numbering.membership.pattern', 'ADH/{ABR}/{YYYY}/{N:6}');

    validateMember($manager, readyDraft($manager))->assertJsonPath('data.membership_number', 'ADH/ABC/2026/000001');
});

it('RG-ADH-02 exige le droit d\'adhésion entièrement payé, paiements confirmés', function () {
    $manager = centreManager();
    $member = readyDraft($manager);
    $charge = MemberCharge::factory()->create(['member_id' => $member->id, 'amount' => 25_000]);
    $pay = fn (int $amount) => inSpace($manager)->postJson("/api/v1/cga/members/{$member->id}/payments", ['amount' => $amount, 'paid_on' => today()->toDateString(), 'method' => 'cash'])->assertCreated();

    validateMember($manager, $member)->assertStatus(409)->assertJsonPath('details.missing', ['entry_fee_payment']);
    $pay(10_000);
    validateMember($manager, $member)->assertStatus(409)->assertJsonPath('details.missing', ['entry_fee_payment']);
    inSpace($manager)->getJson("/api/v1/cga/members/{$member->id}")->assertJsonPath('data.missing_for_validation', ['entry_fee_payment']);
    $pay(15_000);

    validateMember($manager, $member)->assertOk()->assertJsonPath('data.status', 'active');
    expect($charge->fresh()?->status->value)->toBe('paid');
});

it('RG-ADH-02 n\'attend aucun paiement pour un droit d\'adhésion à zéro', function () {
    $manager = centreManager();
    $free = inSpace($manager)->postJson('/api/v1/cga/members', [
        'portfolio' => 'cga', 'entity_type' => 'company', 'company_name' => 'Gratuit', 'legal_form' => 'SARL', 'ncc' => '1212121G',
        'regime' => 'TEE', 'main_activity' => 'Commerce', 'phone' => '0707070707', 'entry_fee_amount' => 0,
    ])->assertCreated()->json('data');

    expect($free['missing_for_validation'])->not->toContain('entry_fee_payment')
        ->and(MemberCharge::query()->withoutGlobalScopes()->where('member_id', $free['id'])->exists())->toBeFalse();
});

it('refuse un dossier déjà validé, un centre sans abréviation, un adhérent d\'un autre CGA, et un rôle sans le droit', function () {
    $manager = centreManager();
    $active = Member::factory()->active()->create(['organization_id' => $manager->organization_id]);
    $other = readyDraft($manager, ['ncc' => '4444444D']);
    Member::factory()->active()->create(['ncc' => '4444444D']);
    $noAbbreviation = Membership::factory()->role(Role::CgaManager)->create(['organization_id' => Organization::factory()->create(['numbering_abbreviation' => null])->id]);

    validateMember($manager, $active)->assertStatus(409)->assertJsonPath('code', 'member_not_draft');
    validateMember($manager, $other)->assertStatus(409)->assertJsonPath('code', 'membership_exclusive');
    validateMember($noAbbreviation, readyDraft($noAbbreviation))->assertStatus(409)->assertJsonPath('code', 'abbreviation_missing');
    validateMember(Membership::factory()->role(Role::CgaBillingOfficer)->create(['organization_id' => $manager->organization_id]), readyDraft($manager))->assertForbidden();
    validateMember($manager, Member::factory()->create())->assertNotFound();
});

it('RG-ADH-03 attribue 20 numéros distincts et consécutifs à 20 validations simultanées', function () {
    $manager = centreManager();
    $members = array_map(static fn (): Member => readyDraft($manager), range(1, 20));

    $outputs = Concurrency::validate(array_map(static fn (Member $member): array => [$manager->id, $member->id], $members));

    $numbers = array_map(static fn (string $output): string => substr($output, 3), $outputs);
    expect(array_filter($outputs, static fn (string $output): bool => str_starts_with($output, 'OK ')))->toHaveCount(20)
        ->and(array_unique($numbers))->toHaveCount(20);
    $sequence = array_map(static fn (string $number): int => (int) substr($number, -4), $numbers);
    sort($sequence);
    expect($sequence)->toBe(range(1, 20));
})->group('concurrency');
