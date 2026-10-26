<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organizations\Actions\StoreCgaImage;
use App\Domain\Organizations\Actions\UpdateCgaProfile;
use App\Domain\Organizations\Enums\AgreementStatus;
use App\Domain\Organizations\Enums\CgaImageKind;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Enums\OrganizationStatusEvent;
use App\Domain\Organizations\Enums\SubscriptionStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Referentials\Models\RegionalDirectorate;
use App\Domain\Referentials\Models\TaxOffice;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Config\SettingsService;
use App\Support\Http\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Organisations (06-IMPLEMENTATION-PLAN étape 1.2, F-ORG-01 à 07) : registre des CGA tenu par le
 * CNCGA, abonnement, suspension et réactivation par la Présidente, perte d'agrément, fiche du CGA
 * complétée par son gérant. L'import du registre (RG-ORG-01) relève de l'étape 1.3.
 */

const PNG_PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

function network(Role $role = Role::CncgaPresident): Membership
{
    return Membership::factory()->role($role)->create();
}

function agentOf(Organization $organization, Role $role = Role::CgaManager): Membership
{
    return Membership::factory()->role($role)->create(['organization_id' => $organization->id]);
}

function pngUpload(string $name = 'image.png', int $paddingBytes = 0): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, base64_decode(PNG_PIXEL).str_repeat("\0", $paddingBytes));
}

/**
 * Fiche complète au sens de RG-ORG-04, hors images.
 *
 * @return array<string, mixed>
 */
function completeProfile(): array
{
    $taxOffice = TaxOffice::factory()->create();

    return [
        'legal_form' => 'Association', 'share_capital' => 1_000_000, 'ncc' => 'CI-1234567A', 'rccm' => 'CI-ABJ-2020-B-1234',
        'agreement_number' => 'AGR-'.Str::upper(Str::random(8)), 'agreement_date' => '2020-03-15',
        'regional_directorate_id' => $taxOffice->regional_directorate_id, 'tax_office_id' => $taxOffice->id,
        'address' => 'Plateau, rue du Commerce', 'city' => 'Abidjan', 'phone_1' => '0707070707', 'phone_2' => '27 22 44 55 66',
        'email' => 'contact@centre.ci', 'manager_name' => 'Koffi Kouadio', 'expert_name' => 'Cabinet Expert',
        'numbering_abbreviation' => Str::upper(fake()->unique()->lexify('CT????')),
    ];
}

/**
 * Fiche minimale du registre (F-ORG-02).
 *
 * @return array<string, mixed>
 */
function registration(array $overrides = []): array
{
    return [
        'name' => 'CGA du Zanzan', 'sigle' => 'CGAZ', 'city' => 'Bondoukou', 'agreement_status' => 'granted',
        'agreement_number' => 'AGR-'.Str::upper(Str::random(8)), 'agreement_date' => '2019-06-01',
        'phone_1' => '0707070707', 'email' => 'contact@zanzan.ci', ...$overrides,
    ];
}

beforeEach(function () {
    Storage::fake();
});

describe('registre des CGA (F-ORG-01, 02, 04)', function () {
    it('liste les seuls CGA, paginés, avec les actions permises', function () {
        Organization::factory()->count(2)->create();
        $president = network();
        Organization::factory()->platform()->create();

        inSpace($president)->getJson('/api/v1/network/organizations')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.can', ['update' => true, 'change_subscription' => true, 'suspend' => true, 'invite_manager' => true]);
    });

    it('filtre le registre par statut, abonnement, agrément, direction régionale et recherche sans accent', function () {
        $directorate = RegionalDirectorate::factory()->create();
        Organization::factory()->create(['name' => 'CGA Éburnie', 'regional_directorate_id' => $directorate->id]);
        Organization::factory()->suspended()->create(['subscription_status' => SubscriptionStatus::Trial]);
        Organization::factory()->create(['agreement_status' => AgreementStatus::Pending, 'agreement_number' => null]);
        $president = network();

        $count = fn (string $query): int => count((array) inSpace($president)->getJson('/api/v1/network/organizations?'.$query)->assertOk()->json('data'));

        expect($count('filter[status]=suspended'))->toBe(1)
            ->and($count('filter[subscription_status]=trial'))->toBe(1)
            ->and($count('filter[agreement_status]=pending'))->toBe(1)
            ->and($count('filter[regional_directorate_id]='.$directorate->id))->toBe(1)
            ->and($count('filter[search]=eburnie'))->toBe(1);
        inSpace($president)->getJson('/api/v1/network/organizations?filter[status]=inconnu')->assertUnprocessable();
    });

    it('RG-ORG-03 crée un CGA à l\'agrément en cours, non abonné, téléphone au format E.164', function () {
        $response = inSpace(network(Role::CncgaManager))->postJson('/api/v1/network/organizations', registration([
            'agreement_status' => 'pending', 'agreement_number' => null, 'agreement_date' => null, 'phone_1' => '27 22 44 55 66',
        ]))->assertCreated()
            ->assertJsonPath('data.agreement_status', 'pending')
            ->assertJsonPath('data.agreement_status_label', 'Agrément en cours')
            ->assertJsonPath('data.subscription.status', 'none')
            ->assertJsonPath('data.phone_1', '+2252722445566');

        $organization = Organization::query()->findOrFail($response->json('data.id'));
        expect($organization->type->value)->toBe('cga')
            ->and(AuditLog::query()->where('event', 'created')->where('subject_id', $organization->id)->exists())->toBeTrue();
    });

    it('exige le numéro et la date d\'un agrément accordé, uniques et passés', function () {
        $taken = Organization::factory()->create();
        $manager = network(Role::CncgaManager);

        inSpace($manager)->postJson('/api/v1/network/organizations', registration(['agreement_number' => null, 'agreement_date' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors(['agreement_number', 'agreement_date']);
        inSpace($manager)->postJson('/api/v1/network/organizations', registration(['agreement_number' => $taken->agreement_number, 'agreement_date' => now()->addDay()->toDateString()]))
            ->assertUnprocessable()->assertJsonValidationErrors(['agreement_number', 'agreement_date']);
        inSpace($manager)->postJson('/api/v1/network/organizations', registration(['name' => '', 'phone_1' => '12', 'agreement_status' => 'withdrawn']))
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'phone_1', 'agreement_status']);
    });

    it('RG-ORG-02 ne prend jamais le nombre d\'adhérents dans la saisie', function () {
        inSpace(network())->postJson('/api/v1/network/organizations', registration(['members_count' => 999]))
            ->assertCreated()
            ->assertJsonPath('data.members_count', 0);
    });

    it('montre la fiche détaillée d\'un CGA avec son historique, et modifie sa fiche minimale', function () {
        $organization = Organization::factory()->create();
        $president = network();
        inSpace($president)->postJson("/api/v1/network/organizations/{$organization->id}/suspend", ['reason' => 'Contrôle'])->assertOk();

        inSpace($president)->getJson("/api/v1/network/organizations/{$organization->id}")
            ->assertOk()
            ->assertJsonPath('data.status_events.0.event', 'suspended')
            ->assertJsonPath('data.status_events.0.reason', 'Contrôle')
            ->assertJsonPath('data.status_events.0.actor.id', $president->user_id);

        inSpace($president)->putJson("/api/v1/network/organizations/{$organization->id}", registration(['name' => 'Nouveau nom']))
            ->assertOk()
            ->assertJsonPath('data.name', 'Nouveau nom');
        expect(AuditLog::query()->where('event', 'updated')->where('subject_id', $organization->id)->exists())->toBeTrue();
    });

    it('ne traite pas le CNCGA ni la plateforme comme un CGA du registre', function () {
        $president = network();

        inSpace($president)->getJson("/api/v1/network/organizations/{$president->organization_id}")->assertForbidden();
        inSpace($president)->postJson("/api/v1/network/organizations/{$president->organization_id}/suspend", ['reason' => 'X'])->assertForbidden();
        inSpace($president)->getJson('/api/v1/network/organizations/'.strtolower((string) Str::ulid()))->assertNotFound();
    });
});

describe('droits sur le registre (PRD §3.3)', function () {
    /*
     * Rôle => [consulter, créer et modifier, abonnement, suspendre].
     */
    dataset('rôles du réseau', [
        'Présidente' => [Role::CncgaPresident, true, true, true, true],
        'gestionnaire du CNCGA' => [Role::CncgaManager, true, true, true, false],
        'administrateur de la plateforme' => [Role::PlatformAdmin, true, true, true, false],
        'bureau (consultation)' => [Role::CncgaViewer, true, false, false, false],
        'chargé des cotisations' => [Role::CncgaDues, false, false, false, false],
        'chargé des formations' => [Role::CncgaTraining, false, false, false, false],
        'gérant d\'un CGA' => [Role::CgaManager, false, false, false, false],
    ]);

    it('applique la matrice des droits à chaque rôle', function (Role $role, bool $view, bool $manage, bool $subscription, bool $suspend) {
        $organization = Organization::factory()->create(['subscription_status' => SubscriptionStatus::None]);
        $member = network($role);
        $status = fn (bool $allowed, int $success): int => $allowed ? $success : 403;

        inSpace($member)->getJson('/api/v1/network/organizations')->assertStatus($status($view, 200));
        inSpace($member)->getJson("/api/v1/network/organizations/{$organization->id}")->assertStatus($status($view, 200));
        inSpace($member)->postJson('/api/v1/network/organizations', registration())->assertStatus($status($manage, 201));
        inSpace($member)->putJson("/api/v1/network/organizations/{$organization->id}", registration())->assertStatus($status($manage, 200));
        inSpace($member)->postJson("/api/v1/network/organizations/{$organization->id}/subscription", ['status' => 'trial'])->assertStatus($status($subscription, 200));
        inSpace($member)->postJson("/api/v1/network/organizations/{$organization->id}/withdraw-agreement", ['withdrawn_at' => today()->toDateString(), 'reason' => 'Retrait'])->assertStatus($status($suspend, 200));
        inSpace($member)->postJson("/api/v1/network/organizations/{$organization->id}/reactivate", ['reason' => 'Réactivation'])->assertStatus($status($suspend, 200));
        inSpace($member)->postJson("/api/v1/network/organizations/{$organization->id}/suspend", ['reason' => 'Suspension'])->assertStatus($status($suspend, 200));
    })->with('rôles du réseau');
});

describe('abonnement (F-ORG-05)', function () {
    it('ouvre un essai de subscription.trial_months mois et l\'inscrit à l\'historique', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00'));
        $organization = Organization::factory()->create(['subscription_status' => SubscriptionStatus::None, 'subscription_started_at' => null]);

        inSpace(network(Role::CncgaManager))->postJson("/api/v1/network/organizations/{$organization->id}/subscription", ['status' => 'trial'])
            ->assertOk()
            ->assertJsonPath('data.subscription.status', 'trial')
            ->assertJsonPath('data.subscription.started_at', '2026-10-20')
            ->assertJsonPath('data.subscription.trial_ends_at', '2027-01-20')
            ->assertJsonPath('data.status_events.0.event', 'subscription_changed')
            ->assertJsonPath('data.status_events.0.reason', 'Abonnement : Sans abonnement → Essai.');

        app(SettingsService::class)->set('subscription.trial_months', 1);
        $other = Organization::factory()->create(['subscription_status' => SubscriptionStatus::None]);
        inSpace(network())->postJson("/api/v1/network/organizations/{$other->id}/subscription", ['status' => 'trial', 'reason' => 'Pilote'])
            ->assertJsonPath('data.subscription.trial_ends_at', '2026-11-20')
            ->assertJsonPath('data.status_events.0.reason', 'Pilote');
    });

    it('refuse un état d\'abonnement inchangé ou inconnu', function () {
        $organization = Organization::factory()->create();

        inSpace(network())->postJson("/api/v1/network/organizations/{$organization->id}/subscription", ['status' => 'active'])
            ->assertStatus(409)->assertJsonPath('code', 'subscription_unchanged');
        inSpace(network())->postJson("/api/v1/network/organizations/{$organization->id}/subscription", ['status' => 'gratuit'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    });

    it('RG-ORG-07 résilie le CGA : fin d\'abonnement datée, données à supprimer data_retention_months après', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00'));
        $organization = Organization::factory()->create();
        $agent = agentOf($organization);

        inSpace(network())->postJson("/api/v1/network/organizations/{$organization->id}/subscription", ['status' => 'terminated', 'reason' => 'Demande du CGA'])
            ->assertOk()
            ->assertJsonPath('data.status', 'terminated')
            ->assertJsonPath('data.subscription.ended_at', '2026-10-20')
            ->assertJsonPath('data.subscription.data_deletion_due_at', '2027-04-20');

        expect($organization->statusEvents()->pluck('event')->all())->toEqualCanonicalizing([OrganizationStatusEvent::SubscriptionChanged, OrganizationStatusEvent::Terminated]);
        inSpace($agent)->getJson('/api/v1/cga/profile')->assertStatus(409)->assertJsonPath('code', 'space_required');
        inSpace(network())->postJson("/api/v1/network/organizations/{$organization->id}/subscription", ['status' => 'active'])
            ->assertStatus(409)->assertJsonPath('code', 'organization_terminated');
        inSpace(network())->putJson("/api/v1/network/organizations/{$organization->id}", registration())
            ->assertStatus(409)->assertJsonPath('code', 'organization_terminated');
    });

    it('RG-ORG-06 ne donne aucun accès à un CGA non abonné, et l\'ouvre dès l\'essai', function () {
        $organization = Organization::factory()->create(['subscription_status' => SubscriptionStatus::None]);
        $agent = agentOf($organization);

        inSpace($agent)->getJson('/api/v1/cga/profile')->assertStatus(409)->assertJsonPath('code', 'space_required');
        inSpace(network())->getJson("/api/v1/network/organizations/{$organization->id}")->assertOk();

        inSpace(network())->postJson("/api/v1/network/organizations/{$organization->id}/subscription", ['status' => 'trial'])->assertOk();
        inSpace($agent)->getJson('/api/v1/cga/profile')->assertOk();

        inSpace(network())->postJson("/api/v1/network/organizations/{$organization->id}/subscription", ['status' => 'none', 'reason' => 'Fin de l\'essai sans abonnement'])
            ->assertOk()->assertJsonPath('data.subscription.status', 'none');
        inSpace($agent)->getJson('/api/v1/cga/profile')->assertStatus(409)->assertJsonPath('code', 'space_required');
    });
});

describe('suspension, réactivation et perte d\'agrément (F-ORG-06, 07)', function () {
    it('RG-ORG-08 laisse les agents d\'un CGA suspendu consulter, sans rien écrire, jusqu\'à sa réactivation', function () {
        $organization = Organization::factory()->create();
        $agent = agentOf($organization);
        $president = network();

        inSpace($president)->postJson("/api/v1/network/organizations/{$organization->id}/suspend", ['reason' => 'Manquement grave'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.suspension_reason', 'Manquement grave');

        inSpace($agent)->getJson('/api/v1/cga/profile')->assertOk()->assertJsonPath('data.status', 'suspended');
        inSpace($agent)->putJson('/api/v1/cga/profile', completeProfile())->assertStatus(409)->assertJsonPath('code', 'organization_suspended');
        inSpace($agent)->postJson('/api/v1/cga/profile/images/logo', ['image' => pngUpload()])->assertStatus(409)->assertJsonPath('code', 'organization_suspended');

        inSpace($president)->postJson("/api/v1/network/organizations/{$organization->id}/reactivate", ['reason' => 'Situation régularisée'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.suspension_reason', null)
            ->assertJsonPath('data.status_events.0.event', 'reactivated');
        inSpace($agent)->putJson('/api/v1/cga/profile', completeProfile())->assertOk();
    });

    it('RG-ORG-08 laisse les adhérents d\'un CGA suspendu agir sur leurs documents', function () {
        Route::middleware(['api', 'auth', 'tenant'])->post('api/v1/essai-adherent', fn () => ['ok' => true]);
        $organization = Organization::factory()->suspended()->create();
        $member = Membership::factory()->role(Role::MemberOwner)->create(['organization_id' => $organization->id]);

        inSpace($member)->postJson('/api/v1/essai-adherent')->assertOk();
        inSpace(agentOf($organization))->postJson('/api/v1/essai-adherent')->assertStatus(409);
    });

    it('exige un motif, et refuse une suspension ou une réactivation sans objet', function () {
        $organization = Organization::factory()->suspended()->create();
        $president = network();

        inSpace($president)->postJson("/api/v1/network/organizations/{$organization->id}/suspend", ['reason' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        inSpace($president)->postJson("/api/v1/network/organizations/{$organization->id}/suspend", ['reason' => 'Encore'])
            ->assertStatus(409)->assertJsonPath('code', 'organization_already_suspended');
        $active = Organization::factory()->create();
        inSpace($president)->postJson("/api/v1/network/organizations/{$active->id}/reactivate", ['reason' => 'Rien'])
            ->assertStatus(409)->assertJsonPath('code', 'organization_not_suspended');
        $terminated = Organization::factory()->create(['status' => OrganizationStatus::Terminated]);
        inSpace($president)->postJson("/api/v1/network/organizations/{$terminated->id}/suspend", ['reason' => 'Trop tard'])
            ->assertStatus(409)->assertJsonPath('code', 'organization_terminated');
    });

    it('RG-ORG-09 ne suspend jamais seul : seule la Présidente suspend, droit verrouillé', function () {
        expect(config('permissions.permissions')['organizations.suspend'])->toBe(['locked' => true, 'roles' => ['cncga_president']]);

        $organization = Organization::factory()->create(['subscription_status' => SubscriptionStatus::Terminated]);
        inSpace(network(Role::CncgaManager))->postJson("/api/v1/network/organizations/{$organization->id}/suspend", ['reason' => 'Impayé'])->assertForbidden();
        expect($organization->refresh()->status)->toBe(OrganizationStatus::Active);
    });

    it('marque la perte d\'agrément à sa date et suspend le CGA, sans radier personne (F-ORG-07)', function () {
        $organization = Organization::factory()->create();

        inSpace(network())->postJson("/api/v1/network/organizations/{$organization->id}/withdraw-agreement", ['withdrawn_at' => '2026-10-01', 'reason' => 'Retrait par la DGI'])
            ->assertOk()
            ->assertJsonPath('data.agreement_status', 'withdrawn')
            ->assertJsonPath('data.agreement_withdrawn_at', '2026-10-01')
            ->assertJsonPath('data.status', 'suspended');

        expect($organization->statusEvents()->pluck('event')->all())->toEqualCanonicalizing([OrganizationStatusEvent::AgreementWithdrawn, OrganizationStatusEvent::Suspended]);
        inSpace(network())->postJson("/api/v1/network/organizations/{$organization->id}/withdraw-agreement", ['withdrawn_at' => '2026-10-01', 'reason' => 'Encore'])
            ->assertStatus(409)->assertJsonPath('code', 'agreement_already_withdrawn');
    });

    it('retire l\'agrément d\'un CGA déjà suspendu sans le suspendre deux fois, à une date passée seulement', function () {
        $organization = Organization::factory()->suspended()->create();

        inSpace(network())->postJson("/api/v1/network/organizations/{$organization->id}/withdraw-agreement", ['withdrawn_at' => now()->addDay()->toDateString(), 'reason' => 'X'])
            ->assertUnprocessable()->assertJsonValidationErrors('withdrawn_at');
        inSpace(network())->postJson("/api/v1/network/organizations/{$organization->id}/withdraw-agreement", ['withdrawn_at' => today()->toDateString(), 'reason' => 'Retrait'])
            ->assertOk();

        expect($organization->statusEvents()->pluck('event')->all())->toBe([OrganizationStatusEvent::AgreementWithdrawn]);
        $terminated = Organization::factory()->create(['status' => OrganizationStatus::Terminated]);
        inSpace(network())->postJson("/api/v1/network/organizations/{$terminated->id}/withdraw-agreement", ['withdrawn_at' => today()->toDateString(), 'reason' => 'X'])
            ->assertStatus(409)->assertJsonPath('code', 'organization_terminated');
        inSpace(network())->postJson("/api/v1/network/organizations/{$terminated->id}/reactivate", ['reason' => 'X'])
            ->assertStatus(409)->assertJsonPath('code', 'organization_not_suspended');
    });
});

describe('fiche du CGA (F-ORG-03)', function () {
    it('montre au gérant sa fiche, les champs exigés encore vides et ses droits', function () {
        $organization = Organization::factory()->create(['ncc' => null]);

        inSpace(agentOf($organization))->getJson('/api/v1/cga/profile')
            ->assertOk()
            ->assertJsonPath('data.id', $organization->id)
            ->assertJsonPath('data.profile_completed_at', null)
            ->assertJsonPath('data.images', ['logo' => null, 'signature' => null, 'stamp' => null])
            ->assertJsonPath('data.can.update', true)
            ->assertJsonFragment(['missing_fields' => ['ncc', 'rccm', 'legal_form', 'share_capital', 'regional_directorate_id', 'tax_office_id', 'address', 'manager_name', 'expert_name', 'logo_file_id', 'signature_file_id', 'stamp_file_id']]);
    });

    it('laisse l\'expert-comptable consulter la fiche sans la modifier, et la refuse aux autres rôles', function () {
        $organization = Organization::factory()->create();

        inSpace(agentOf($organization, Role::CgaAccountantExpert))->getJson('/api/v1/cga/profile')->assertOk()->assertJsonPath('data.can.update', false);
        inSpace(agentOf($organization, Role::CgaAccountantExpert))->putJson('/api/v1/cga/profile', completeProfile())->assertForbidden();
        inSpace(agentOf($organization, Role::CgaAccountantExpert))->postJson('/api/v1/cga/profile/images/logo', ['image' => pngUpload()])->assertForbidden();
        inSpace(agentOf($organization, Role::CgaAccountant))->getJson('/api/v1/cga/profile')->assertForbidden();
        inSpace(network())->getJson('/api/v1/cga/profile')->assertForbidden();
        inSpace(network())->putJson('/api/v1/cga/profile', completeProfile())->assertForbidden();
    });

    it('RG-ORG-04 date la complétude quand tous les champs exigés et les trois images sont là, et l\'efface sinon', function () {
        $organization = Organization::factory()->create(['numbering_abbreviation' => null]);
        $manager = agentOf($organization);

        inSpace($manager)->putJson('/api/v1/cga/profile', completeProfile())
            ->assertOk()
            ->assertJsonPath('data.phone_1', '+2250707070707')
            ->assertJsonPath('data.phone_2', '+2252722445566')
            ->assertJsonPath('data.missing_fields', ['logo_file_id', 'signature_file_id', 'stamp_file_id'])
            ->assertJsonPath('data.profile_completed_at', null);

        inSpace($manager)->postJson('/api/v1/cga/profile/images/logo', ['image' => pngUpload('logo.png')])->assertOk();
        inSpace($manager)->postJson('/api/v1/cga/profile/images/signature', ['image' => pngUpload('signature.png')])->assertOk();
        $response = inSpace($manager)->postJson('/api/v1/cga/profile/images/stamp', ['image' => pngUpload('cachet.png')])
            ->assertOk()
            ->assertJsonPath('data.missing_fields', []);
        expect($response->json('data.profile_completed_at'))->not->toBeNull()
            ->and($response->json('data.images.stamp.mime_type'))->toBe('image/png')
            ->and($response->json('data.images.stamp.url'))->toContain('/api/v1/files/')->toContain('signature=');

        inSpace($manager)->putJson('/api/v1/cga/profile', [...completeProfile(), 'numbering_abbreviation' => $organization->refresh()->numbering_abbreviation, 'expert_name' => null])
            ->assertOk()
            ->assertJsonPath('data.missing_fields', ['expert_name'])
            ->assertJsonPath('data.profile_completed_at', null);
    });

    it('RG-ORG-05 fige l\'abréviation de numérotation après le premier numéro d\'adhésion', function () {
        $organization = Organization::factory()->create(['numbering_abbreviation' => 'DCK', 'abbreviation_locked_at' => now()]);
        $manager = agentOf($organization);

        inSpace($manager)->putJson('/api/v1/cga/profile', [...completeProfile(), 'numbering_abbreviation' => 'DCKN'])
            ->assertStatus(409)->assertJsonPath('code', 'abbreviation_locked');
        inSpace($manager)->putJson('/api/v1/cga/profile', [...completeProfile(), 'numbering_abbreviation' => 'DCK'])
            ->assertOk()->assertJsonPath('data.abbreviation_locked', true);
        inSpace($manager)->putJson('/api/v1/cga/profile', [...completeProfile(), 'numbering_abbreviation' => null])
            ->assertOk()->assertJsonPath('data.numbering_abbreviation', 'DCK');
    });

    it('RG-ORG-05 veut une abréviation de 2 à 6 majuscules, unique dans le réseau', function () {
        $taken = Organization::factory()->create();
        $manager = agentOf(Organization::factory()->create());

        foreach (['dck', 'D', 'ABCDEFG', 'DC-K', $taken->numbering_abbreviation] as $abbreviation) {
            inSpace($manager)->putJson('/api/v1/cga/profile', [...completeProfile(), 'numbering_abbreviation' => $abbreviation])
                ->assertUnprocessable()->assertJsonValidationErrors('numbering_abbreviation');
        }
    });

    it('n\'accepte qu\'un CDI de la direction régionale choisie', function () {
        $manager = agentOf(Organization::factory()->create());
        $otherOffice = TaxOffice::factory()->create();

        inSpace($manager)->putJson('/api/v1/cga/profile', [...completeProfile(), 'tax_office_id' => $otherOffice->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.tax_office_id.0', 'Ce CDI n\'appartient pas à la direction régionale choisie.');
        inSpace($manager)->putJson('/api/v1/cga/profile', [...completeProfile(), 'regional_directorate_id' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('regional_directorate_id');
    });

    it('n\'accepte comme image qu\'un PNG ou un JPEG, dans la limite de files.max_upload_mb', function () {
        $manager = agentOf(Organization::factory()->create());
        app(SettingsService::class)->set('files.max_upload_mb', 1);

        inSpace($manager)->postJson('/api/v1/cga/profile/images/logo', ['image' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')])
            ->assertUnprocessable()->assertJsonValidationErrors('image');
        inSpace($manager)->postJson('/api/v1/cga/profile/images/logo', ['image' => pngUpload('lourd.png', 1_100_000)])
            ->assertUnprocessable()->assertJsonValidationErrors('image');
        inSpace($manager)->postJson('/api/v1/cga/profile/images/logo', [])
            ->assertUnprocessable()->assertJsonValidationErrors('image');
        inSpace($manager)->postJson('/api/v1/cga/profile/images/banniere', ['image' => pngUpload()])->assertNotFound();
    });

    it('ne modifie plus la fiche ni les images d\'un CGA résilié', function () {
        $organization = Organization::factory()->create(['status' => OrganizationStatus::Terminated]);
        $user = agentOf($organization)->user;

        expect(fn () => app(UpdateCgaProfile::class)->execute($organization, ['city' => 'Abidjan']))
            ->toThrow(BusinessRuleException::class)
            ->and(fn () => app(StoreCgaImage::class)->execute($organization, CgaImageKind::Logo, pngUpload(), $user))
            ->toThrow(BusinessRuleException::class);
    });

    it('range l\'image dans les fichiers du CGA, nom régénéré', function () {
        $organization = Organization::factory()->create();

        $response = inSpace(agentOf($organization))->postJson('/api/v1/cga/profile/images/signature', ['image' => pngUpload('ma signature.png')])->assertOk();

        $file = $organization->refresh()->signature;
        expect($file?->id)->toBe($response->json('data.images.signature.id'))
            ->and($file?->organization_id)->toBe($organization->id)
            ->and($file?->path)->toStartWith("orgs/{$organization->id}/branding/");
        Storage::assertExists((string) $file?->path);
    });
});
