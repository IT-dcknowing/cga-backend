<?php

use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Members\Models\Member;
use App\Domain\Obligations\Actions\GenerateObligationInstances;
use App\Domain\Obligations\Actions\LoadObligationCatalog;
use App\Domain\Obligations\Actions\SuggestMemberObligations;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Enums\MemberObligationStatus;
use App\Domain\Obligations\Models\MemberObligation;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Obligations\Models\ObligationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Referentials\Enums\DataStatus;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Versions du catalogue national des obligations (06-IMPLEMENTATION-PLAN étape 4.9, RG-DATA-02, 04,
 * décision 0005) : aperçu des effets d'une nouvelle version sur les dossiers, en nombres seulement ;
 * application — obligations conservées rattachées à la version, obligations retirées closes,
 * nouvelles proposées au CGA, échéances à venir non commencées recalculées, passées inchangées ;
 * validation par le CNCGA. Critère du plan : passage d'une version à l'autre sur 50 adhérents de deux
 * centres. Catalogue d'essai : jamais le référentiel de DC-KNOWING. Jour de référence : 26/10/2026.
 */

beforeEach(function () {
    Storage::fake();
    $this->travelTo('2026-10-26 10:00:00');
});

/**
 * Version d'essai : MENS (déclaration mensuelle TEE), TRIM (paiement trimestriel TEE), DILI
 * (diligence mensuelle, tous régimes) ; `$changes` remplace ou retire (null) une obligation par code.
 *
 * @param  array<string, array<string, mixed>|null>  $changes
 * @return array<string, mixed>
 */
function essayVersion(string $version, array $changes = []): array
{
    $obligations = [
        'MENS' => ['code' => 'MENS', 'label' => 'Déclaration mensuelle d\'essai', 'category' => 'tax', 'authority' => 'DGI', 'platform' => 'e-impôts', 'kind' => 'declaration',
            'periodicity' => 'monthly', 'due_rule' => ['type' => 'day_of_next_month', 'day' => 15], 'applicability' => ['regimes' => ['TEE'], 'min_employees' => null, 'sectors' => []], 'tax_nature' => 'tee'],
        'TRIM' => ['code' => 'TRIM', 'label' => 'Paiement trimestriel d\'essai', 'category' => 'tax', 'authority' => 'DGI', 'platform' => null, 'kind' => 'payment',
            'periodicity' => 'quarterly', 'due_rule' => ['type' => 'day_after_period_end', 'days' => 20], 'applicability' => ['regimes' => ['TEE'], 'min_employees' => null, 'sectors' => []], 'tax_nature' => 'tee'],
        'DILI' => ['code' => 'DILI', 'label' => 'Diligence mensuelle d\'essai', 'category' => 'other', 'authority' => 'Commune', 'platform' => null, 'kind' => 'diligence',
            'periodicity' => 'monthly', 'due_rule' => ['type' => 'day_of_next_month', 'day' => 10], 'applicability' => ['regimes' => [], 'min_employees' => null, 'sectors' => []]],
    ];
    foreach ($changes as $code => $obligation) {
        if ($obligation === null) {
            unset($obligations[$code]);
        } else {
            $obligations[$code] = ['code' => $code, ...($obligations[$code] ?? []), ...$obligation];
        }
    }

    return ['version' => $version, 'obligations' => array_values($obligations)];
}

/**
 * Version 2026.2 : MENS change de jour d'échéance, TRIM devient mensuel, DILI disparaît, NOUV arrive.
 *
 * @return array<string, mixed>
 */
function nextEssayVersion(): array
{
    return essayVersion('2026.2', [
        'MENS' => ['due_rule' => ['type' => 'day_of_next_month', 'day' => 20]],
        'TRIM' => ['periodicity' => 'monthly', 'due_rule' => ['type' => 'day_of_next_month', 'day' => 15]],
        'DILI' => null,
        'NOUV' => ['label' => 'Nouvelle obligation d\'essai', 'category' => 'other', 'authority' => 'Commune', 'platform' => null, 'kind' => 'declaration', 'periodicity' => 'yearly',
            'due_rule' => ['type' => 'fixed_date', 'month' => 3, 'day' => 31], 'applicability' => ['regimes' => [], 'min_employees' => null, 'sectors' => []]],
    ]);
}

function catalogUpload(array $catalog): UploadedFile
{
    return UploadedFile::fake()->createWithContent('catalogue.json', (string) json_encode($catalog));
}

/**
 * 50 dossiers validés dans deux centres (40 au TEE, 10 au RME), obligations de la version 2026.1
 * confirmées, échéances générées au 26/10/2026.
 *
 * @return array{0: Organization, 1: Organization}
 */
function fiftyMembersOnFirstVersion(): array
{
    app(LoadObligationCatalog::class)->execute(essayVersion('2026.1'));
    [$first, $second] = [Organization::factory()->create(), Organization::factory()->create()];
    $members = [
        ...Member::factory()->active()->count(30)->create(['organization_id' => $first->id, 'regime' => TaxRegime::Tee])->all(),
        ...Member::factory()->active()->count(10)->create(['organization_id' => $second->id, 'regime' => TaxRegime::Tee])->all(),
        ...Member::factory()->active()->count(10)->create(['organization_id' => $second->id, 'regime' => TaxRegime::Rme])->all(),
    ];

    Tenancy::bypass(static function () use ($members): void {
        foreach ($members as $member) {
            app(SuggestMemberObligations::class)->execute($member);
        }
        MemberObligation::query()->update(['status' => MemberObligationStatus::Active, 'confirmed_at' => now(), 'start_date' => '2026-10-01']);
    }, 'Test : obligations confirmées.');
    app(GenerateObligationInstances::class)->execute(CarbonImmutable::parse('2026-10-26'));

    return [$first, $second];
}

function catalogAdmin(Role $role = Role::CncgaPresident): Membership
{
    return Membership::factory()->role($role)->create();
}

/**
 * @return Collection<int, ObligationInstance>
 */
function instancesOf(string $code): Collection
{
    return Tenancy::bypass(static fn () => ObligationInstance::query()
        ->whereIn('member_obligation_id', MemberObligation::query()->whereIn('obligation_type_id', ObligationType::query()->where('code', $code)->select('id'))->select('id'))
        ->get(), 'Test : échéances d\'une obligation.');
}

describe('nouvelle version du catalogue (RG-DATA-04, décision 0005)', function () {
    it('RG-DATA-04 · 50 adhérents de deux centres : aperçu en nombres, puis application conforme à l\'aperçu', function () {
        fiftyMembersOnFirstVersion();
        $admin = catalogAdmin();
        // Une échéance à venir déjà faite : elle ne bouge pas.
        $started = instancesOf('MENS')->firstWhere('period_label', '2026-10');
        Tenancy::bypass(static fn () => $started->forceFill(['status' => InstanceStatus::Done, 'done_at' => '2026-10-20'])->save(), 'Test.');
        $past = instancesOf('MENS')->where('period_label', '2026-09')->pluck('due_date', 'id')->map->toDateString();
        $members = ['suggested' => 50, 'withdrawn' => 0, 'ended' => 50, 'rescheduled' => 40 * 3 - 1 + 40, 'members' => 50, 'organizations' => 2];

        $preview = inSpace($admin)->post('/api/v1/network/obligation-catalog/preview', ['file' => catalogUpload(nextEssayVersion())], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.version', '2026.2')
            ->assertJsonPath('data.types.added', ['NOUV'])
            ->assertJsonPath('data.types.removed', ['DILI'])
            ->assertJsonPath('data.types.changed', [['code' => 'MENS', 'fields' => ['due_rule']], ['code' => 'TRIM', 'fields' => ['periodicity', 'due_rule']]])
            ->assertJsonPath('data.types.unchanged', 0)
            ->assertJsonPath('data.members', $members);
        expect($preview->getContent())->not->toContain('company_name')
            ->and(Tenancy::bypass(static fn () => ObligationType::query()->where('catalog_version', '2026.2')->exists(), 'Test.'))->toBeFalse();

        inSpace($admin)->postJson('/api/v1/network/obligation-catalog/apply', ['file_id' => $preview->json('data.file_id')])
            ->assertOk()
            ->assertJsonPath('data', ['version' => '2026.2', 'loaded' => 3, ...array_intersect_key($members, array_flip(['suggested', 'withdrawn', 'ended', 'rescheduled']))]);

        Tenancy::bypass(function (): void {
            $v1 = ObligationType::query()->where('catalog_version', '2026.1')->get();
            $v2 = ObligationType::query()->where('catalog_version', '2026.2')->get()->keyBy('code');
            expect($v1->every(fn (ObligationType $type): bool => ! $type->active))->toBeTrue()
                ->and($v2->every(fn (ObligationType $type): bool => $type->active && $type->data_status === DataStatus::Provisional))->toBeTrue();

            // Obligations conservées : même dossier, même statut, nouvelle version.
            expect(MemberObligation::query()->where('obligation_type_id', $v2['MENS']->id)->where('status', MemberObligationStatus::Active)->count())->toBe(40)
                ->and(MemberObligation::query()->where('obligation_type_id', $v2['TRIM']->id)->count())->toBe(40)
                // Retirée : close au jour de l'application ; nouvelle : proposée au CGA.
                ->and(MemberObligation::query()->whereHas('type', fn ($type) => $type->where('code', 'DILI'))->where('status', MemberObligationStatus::Removed)->where('end_date', '2026-10-26')->count())->toBe(50)
                ->and(MemberObligation::query()->where('obligation_type_id', $v2['NOUV']->id)->where('status', MemberObligationStatus::Suggested)->count())->toBe(50);
        }, 'Test : effets appliqués.');

        $mens = instancesOf('MENS');
        expect($mens->where('period_label', '2026-10')->where('status', InstanceStatus::Todo)->pluck('due_date')->map->toDateString()->unique()->values()->all())->toBe(['2026-11-20'])
            ->and($mens->where('period_label', '2026-11')->pluck('due_date')->map->toDateString()->unique()->values()->all())->toBe(['2026-12-21'])
            ->and($mens->firstWhere('id', $started->id)->due_date->toDateString())->toBe('2026-11-16')
            ->and($mens->whereIn('id', $past->keys())->pluck('due_date', 'id')->map->toDateString()->all())->toEqual($past->all());
        expect(instancesOf('TRIM')->where('period_label', '2026-T4')->pluck('status')->unique()->values()->all())->toBe([InstanceStatus::Cancelled])
            ->and(instancesOf('DILI')->where('due_date', '>', CarbonImmutable::parse('2026-10-26'))->pluck('status')->unique()->values()->all())->toBe([InstanceStatus::Cancelled])
            ->and(instancesOf('DILI')->where('period_label', '2026-09')->pluck('status')->unique()->values()->all())->toBe([InstanceStatus::NotEstablished]);

        // La nuit suivante, TRIM reçoit ses échéances mensuelles.
        app(GenerateObligationInstances::class)->execute(CarbonImmutable::parse('2026-10-27'));
        expect(instancesOf('TRIM')->where('period_label', '2026-11')->count())->toBe(40);

        // Rejouer la version en vigueur ne change plus rien.
        inSpace($admin)->postJson('/api/v1/network/obligation-catalog/apply', ['file_id' => $preview->json('data.file_id')])
            ->assertOk()->assertJsonPath('data.suggested', 0)->assertJsonPath('data.ended', 0)->assertJsonPath('data.rescheduled', 0);
    });

    it('RG-DATA-02 · liste les versions chargées ; le CNCGA valide une version', function () {
        app(LoadObligationCatalog::class)->execute(essayVersion('2026.1'));
        $admin = catalogAdmin(Role::CncgaManager);

        inSpace($admin)->getJson('/api/v1/network/obligation-catalog')
            ->assertOk()->assertJsonPath('data.0', ['version' => '2026.1', 'count' => 3, 'provisional_count' => 3, 'status' => 'provisional', 'in_force' => true, 'loaded_at' => '2026-10-26T10:00:00Z', 'validated_at' => null]);
        inSpace($admin)->postJson('/api/v1/network/obligation-catalog/2026.1/validate')->assertOk()->assertJsonPath('data', ['version' => '2026.1', 'validated' => 3]);
        inSpace($admin)->getJson('/api/v1/network/obligation-catalog')
            ->assertOk()->assertJsonPath('data.0.status', 'validated')->assertJsonPath('data.0.validated_at', '2026-10-26T10:00:00Z');
        inSpace($admin)->postJson('/api/v1/network/obligation-catalog/1999.9/validate')->assertNotFound();
    });

    it('refuse un fichier illisible ou un catalogue invalide ; un fichier d\'aperçu inconnu répond 404', function () {
        $admin = catalogAdmin(Role::PlatformAdmin);
        $url = '/api/v1/network/obligation-catalog/preview';

        inSpace($admin)->post($url, ['file' => UploadedFile::fake()->createWithContent('catalogue.json', '{pas du json')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        inSpace($admin)->post($url, ['file' => catalogUpload(['version' => '2026.2', 'obligations' => [['code' => 'X']]])], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['obligations.0.label', 'obligations.0.category']);
        inSpace($admin)->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('file');
        inSpace($admin)->postJson('/api/v1/network/obligation-catalog/apply', ['file_id' => strtolower((string) Str::ulid())])->assertNotFound();
        inSpace($admin)->postJson('/api/v1/network/obligation-catalog/apply', ['file_id' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('file_id');
    });

    it('réservé au réseau qui gère les référentiels : ni un CGA ni le bureau du CNCGA', function (Role $role, int $status) {
        inSpace(catalogAdmin($role))->getJson('/api/v1/network/obligation-catalog')->assertStatus($status);
    })->with([
        'Présidente' => [Role::CncgaPresident, 200],
        'gérant du CNCGA' => [Role::CncgaManager, 200],
        'administrateur de la plateforme' => [Role::PlatformAdmin, 200],
        'bureau du CNCGA' => [Role::CncgaViewer, 403],
        'gérant d\'un CGA' => [Role::CgaManager, 403],
    ]);
});
