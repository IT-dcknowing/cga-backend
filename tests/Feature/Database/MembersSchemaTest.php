<?php

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Enums\RadiationInitiator;
use App\Domain\Members\Enums\RadiationReason;
use App\Domain\Members\Models\BusinessReferrer;
use App\Domain\Members\Models\FeeSchedule;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\MemberPiece;
use App\Domain\Members\Models\MemberRegimeChange;
use App\Domain\Members\Models\MemberRevenue;
use App\Domain\Members\Models\MemberTransfer;
use App\Domain\Members\Models\PortfolioMovement;
use App\Domain\Members\Models\Radiation;
use App\Domain\Referentials\Models\ActivityCode;
use App\Domain\Referentials\Models\Sector;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Tables des adhérents et des portefeuilles (06-IMPLEMENTATION-PLAN étape 2.1, 05-BACKEND-SCHEMA §5) :
 * contraintes de la base, chiffrement, recherche, portefeuille réglementaire.
 */

function memberRow(Member $member, array $overrides = []): array
{
    return [...collect($member->getAttributes())->except(['id', 'search_text', 'created_at', 'updated_at'])->all(), 'id' => strtolower((string) Str::ulid()), ...$overrides];
}

function refusedBySchema(Closure $insert): void
{
    expect(fn () => DB::transaction($insert))->toThrow(QueryException::class);
}

it('crée les tables du dossier des adhérents', function (string $table) {
    expect(Schema::hasTable($table))->toBeTrue();
})->with([
    'members', 'member_regime_history', 'member_revenues', 'member_pieces', 'radiations', 'member_transfers',
    'fee_schedules', 'business_referrers', 'portfolio_movements', 'activity_codes', 'audit_attachments',
]);

it('n\'accepte qu\'un NCC normalisé : 7 chiffres et une lettre', function () {
    $member = Member::factory()->create(['ncc' => '1864699A']);

    refusedBySchema(fn () => DB::table('members')->insert(memberRow($member, ['ncc' => '1864699 a'])));
    expect($member->ncc)->toBe('1864699A');
});

it('RG-ADH-05 n\'ouvre qu\'un dossier par NCC dans un centre, tous portefeuilles confondus', function () {
    $member = Member::factory()->create(['ncc' => '1234567B']);

    refusedBySchema(fn () => DB::table('members')->insert(memberRow($member, ['portfolio' => Portfolio::Cabinet->value])));
    DB::table('members')->insert(memberRow($member, ['organization_id' => Member::factory()->create()->organization_id]));
    DB::table('members')->where('id', $member->id)->update(['status' => MemberStatus::Radiated->value, 'cga_contract_signed_on' => '2025-01-01']);
    DB::table('members')->insert(memberRow($member));

    expect(DB::table('members')->where('ncc', '1234567B')->count())->toBe(3);
});

it('RG-PTF-06 et RG-PTF-08 : apporteur et contrat CGA réservés au portefeuille CGA', function () {
    $member = Member::factory()->create();
    $referrer = BusinessReferrer::factory()->create(['organization_id' => $member->organization_id]);

    refusedBySchema(fn () => DB::table('members')->insert(memberRow($member, ['ncc' => '1111111A', 'portfolio' => 'cabinet', 'referrer_id' => $referrer->id])));
    refusedBySchema(fn () => DB::table('members')->insert(memberRow($member, ['ncc' => '2222222A', 'status' => 'active', 'cga_contract_signed_on' => null])));
    DB::table('members')->insert(memberRow($member, ['ncc' => '3333333A', 'portfolio' => 'cabinet', 'status' => 'active', 'cga_contract_signed_on' => null]));

    expect(DB::table('members')->where('ncc', '3333333A')->exists())->toBeTrue();
});

// Décision 0003 : une seule radiation en cours par dossier (et non plus une seule non annulée), pour
// qu'un dossier revenu au portefeuille CGA après une bascule vers le Cabinet puisse être de nouveau radié.
it('RG-ADH-17 n\'ouvre qu\'une radiation en cours par dossier', function () {
    $member = Member::factory()->active()->create();
    $row = static fn (array $overrides = []): array => [
        'id' => strtolower((string) Str::ulid()), 'organization_id' => $member->organization_id, 'member_id' => $member->id,
        'initiator' => RadiationInitiator::Member->value, 'reason' => RadiationReason::Dissatisfaction->value, 'effective_date' => '2026-06-30',
        'observation' => 'x', 'requested_by' => Membership::factory()->create()->user_id, 'requested_at' => now(), ...$overrides,
    ];
    DB::table('radiations')->insert($row(['status' => 'cancelled', 'cancelled_at' => now()]));
    DB::table('radiations')->insert($row(['status' => 'validated', 'validated_at' => now(), 'outcome' => 'moved_to_cabinet']));
    DB::table('radiations')->insert($row());

    refusedBySchema(fn () => DB::table('radiations')->insert($row()));
    refusedBySchema(fn () => DB::table('radiations')->insert($row(['report_quarter' => 5, 'cancelled_at' => now()])));
});

it('RG-PTF-03 et 04 : une bascule vers le CGA porte son contrat, vers le Cabinet sa radiation', function () {
    $member = Member::factory()->create();
    $row = static fn (array $overrides): array => [
        'id' => strtolower((string) Str::ulid()), 'organization_id' => $member->organization_id, 'member_id' => $member->id,
        'effective_date' => '2026-06-30', 'reason' => 'x', 'created_by' => Membership::factory()->create()->user_id, ...$overrides,
    ];

    refusedBySchema(fn () => DB::table('portfolio_movements')->insert($row(['direction' => 'cabinet_to_cga'])));
    refusedBySchema(fn () => DB::table('portfolio_movements')->insert($row(['direction' => 'cga_to_cabinet'])));
    DB::table('portfolio_movements')->insert($row(['direction' => 'cabinet_to_cga', 'contract_signed_on' => '2026-06-01']));

    expect(DB::table('portfolio_movements')->count())->toBe(1);
});

it('relie un accès adhérent à un dossier existant seulement', function () {
    refusedBySchema(fn () => Membership::factory()->create(['role' => Role::MemberOwner, 'member_id' => strtolower((string) Str::ulid())]));

    $access = Membership::factory()->role(Role::MemberOwner)->create();
    expect(Member::query()->withoutGlobalScopes()->findOrFail($access->member_id)->organization_id)->toBe($access->organization_id);
});

it('chiffre la pièce d\'identité du dirigeant et l\'observation d\'une radiation, et cache la première', function () {
    $member = Member::factory()->create(['owner_id_document_number' => 'CNI-0012345']);

    expect(DB::table('members')->where('id', $member->id)->value('owner_id_document_number'))->not->toContain('CNI-0012345')
        ->and($member->toArray())->not->toHaveKey('owner_id_document_number');

    $manager = Membership::factory()->role(Role::CgaManager)->create(['organization_id' => $member->organization_id]);
    app(TenantContext::class)->runAs($manager, function () use ($member, $manager): void {
        $radiation = new Radiation;
        $radiation->member_id = $member->id;
        $radiation->initiator = RadiationInitiator::Cga;
        $radiation->reason = RadiationReason::SeriousBreach;
        $radiation->effective_date = now();
        $radiation->observation = 'Fraude constatée par la commission';
        $radiation->requested_by = $manager->user_id;
        $radiation->requested_at = now();
        $radiation->save();

        expect(DB::table('radiations')->where('id', $radiation->id)->value('observation'))->not->toContain('Fraude')
            ->and($radiation->refresh()->observation)->toBe('Fraude constatée par la commission');
    });
});

it('calcule le texte de recherche et l\'indexe par trigrammes', function () {
    $member = Member::factory()->create(['company_name' => 'ETS KOUADIO & FILS', 'sigle' => 'EKF', 'ncc' => '7654321Z', 'legacy_number' => 'ANC-12', 'phone' => '+2250707070707']);

    expect(DB::table('members')->where('id', $member->id)->value('search_text'))->toBe('ets kouadio & fils ekf 7654321z   anc-12 +2250707070707')
        ->and(DB::table('pg_indexes')->where('indexname', 'members_search_text_trgm')->exists())->toBeTrue();
});

it('RG-PTF-02 ne garde que le portefeuille CGA dans le périmètre réglementaire', function () {
    $organization = Member::factory()->create()->organization_id;
    Member::factory()->cabinet()->create(['organization_id' => $organization]);
    Member::factory()->active()->create(['organization_id' => $organization, 'status' => MemberStatus::Radiated]);
    $manager = Membership::factory()->role(Role::CgaManager)->create(['organization_id' => $organization]);

    app(TenantContext::class)->runAs($manager, function (): void {
        expect(Member::query()->count())->toBe(3)
            ->and(Member::query()->regulatory()->count())->toBe(2)
            ->and(Member::query()->open()->count())->toBe(2);
    });
});

it('relie le dossier à ses pièces, radiations, chiffres d\'affaires, régimes, bascules, accès et référentiels', function () {
    $member = Member::factory()->active()->create(['commune_id' => null]);
    $manager = Membership::factory()->role(Role::CgaManager)->create(['organization_id' => $member->organization_id]);
    $code = new ActivityCode(['code' => 'COM-01', 'label' => 'Commerce de détail', 'sector_id' => Sector::factory()->create()->id]);
    $code->save();

    app(TenantContext::class)->runAs($manager, function () use ($member, $code): void {
        $member->forceFill(['activity_code_id' => $code->id, 'referrer_id' => BusinessReferrer::factory()->create(['organization_id' => $member->organization_id])->id])->save();

        expect($member->pieces()->count())->toBe(0)
            ->and($member->radiations()->count())->toBe(0)
            ->and($member->revenues()->count())->toBe(0)
            ->and($member->regimeHistory()->count())->toBe(0)
            ->and($member->movements()->count())->toBe(0)
            ->and($member->accesses()->count())->toBe(0)
            ->and($member->referrer?->organization_id)->toBe($member->organization_id)
            ->and($member->taxOffice)->not->toBeNull()
            ->and($member->sector)->not->toBeNull()
            ->and($member->commune)->toBeNull()
            ->and($member->activityCode?->sector)->not->toBeNull()
            ->and(ActivityCode::referencedBy())->toBe(['members' => 'activity_code_id']);

        foreach ([MemberPiece::class, Radiation::class, MemberRevenue::class, MemberRegimeChange::class, PortfolioMovement::class] as $model) {
            expect((new $model)->member()->getRelated())->toBeInstanceOf(Member::class);
        }
    });

    expect((new FeeSchedule)->getCasts())->toHaveKey('kind')
        ->and((new MemberTransfer)->getCasts())->toHaveKey('created_at');
});
