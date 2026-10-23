<?php

use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Events\CertificateCampaignFinished;
use App\Domain\Documents\Jobs\RunCertificateCampaign;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use Database\Seeders\TemplateSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * Campagne annuelle d'attestations (06-IMPLEMENTATION-PLAN étape 3.7, RG-DOC-06, 07) : aperçu de
 * l'année (éligibles, déjà pourvus, bloqués avec leur motif), puis production en tâche de fond pour les
 * seuls dossiers éligibles — ni radiés, ni en radiation, ni du portefeuille Cabinet, ni bloqués.
 */

beforeEach(function () {
    Storage::fake();
    $this->seed(TemplateSeeder::class);
    fakePdfConverter();
});

/**
 * @return array{eligible: list<Member>, excluded: list<Member>, blocked: Member, issued: Member}
 */
function campaignPortfolio(Membership $manager): array
{
    $blocked = validatedMember($manager, ['company_name' => 'ETS BLOQUÉE']);
    inSpace($manager)->postJson("/api/v1/cga/members/{$blocked->id}/attestation-blocks", ['trigger' => 'manual', 'reason' => 'Dossier en litige'])->assertCreated();
    $issued = validatedMember($manager);
    GeneratedDocument::factory()->create(['member_id' => $issued->id, 'type' => DocumentType::MembershipCertificate, 'number' => 'ATT-0', 'fiscal_year' => now()->year]);

    return [
        'eligible' => [validatedMember($manager), validatedMember($manager)],
        'excluded' => [
            validatedMember($manager, ['status' => MemberStatus::Radiated]),
            validatedMember($manager, ['status' => MemberStatus::RadiationPending]),
            Member::factory()->active()->cabinet()->create(['organization_id' => $manager->organization_id]),
            Member::factory()->create(['organization_id' => $manager->organization_id]),
        ],
        'blocked' => $blocked,
        'issued' => $issued,
    ];
}

function certificatesOf(Member $member): int
{
    return GeneratedDocument::query()->withoutGlobalScopes()->where('member_id', $member->id)->where('type', DocumentType::MembershipCertificate)->where('fiscal_year', now()->year)->count();
}

it('RG-DOC-07 montre l\'aperçu de l\'année : éligibles, déjà pourvus, bloqués avec leur motif', function () {
    $manager = documentAgent();
    campaignPortfolio($manager);
    validatedMember(documentAgent());

    inSpace($manager)->getJson('/api/v1/cga/certificate-campaign')->assertOk()
        ->assertJsonPath('data.year', now()->year)
        ->assertJsonPath('data.eligible', 2)
        ->assertJsonPath('data.issued', 1)
        ->assertJsonCount(1, 'data.blocked')
        ->assertJsonPath('data.blocked.0.member.company_name', 'ETS BLOQUÉE')
        ->assertJsonPath('data.blocked.0.reason', 'Dossier en litige');
});

it('RG-DOC-06 lance la campagne en tâche de fond : une attestation par dossier éligible, aucun autre', function () {
    config(['queue.default' => 'sync']);
    Event::fake([CertificateCampaignFinished::class]);
    $manager = documentAgent();
    $portfolio = campaignPortfolio($manager);

    inSpace($manager)->postJson('/api/v1/cga/certificate-campaign')->assertStatus(202)
        ->assertJsonPath('data.queued', 2)
        ->assertJsonPath('data.year', now()->year);

    foreach ($portfolio['eligible'] as $member) {
        expect(certificatesOf($member))->toBe(1);
    }
    foreach ([...$portfolio['excluded'], $portfolio['blocked']] as $member) {
        expect(certificatesOf($member))->toBe(0);
    }
    expect(certificatesOf($portfolio['issued']))->toBe(1)
        ->and(GeneratedDocument::query()->withoutGlobalScopes()->where('member_id', $portfolio['eligible'][0]->id)->sole()->status)->toBe(DocumentStatus::Valid);
    Event::assertDispatched(CertificateCampaignFinished::class, fn (CertificateCampaignFinished $event): bool => $event->produced === 2 && $event->skipped === 0 && $event->year === now()->year);

    inSpace($manager)->postJson('/api/v1/cga/certificate-campaign')->assertStatus(409)->assertJsonPath('code', 'certificate_campaign_empty');
});

it('passe et note au journal un dossier qui ne peut plus recevoir son attestation au passage du Job', function () {
    Event::fake([CertificateCampaignFinished::class]);
    $manager = documentAgent();
    $member = validatedMember($manager);

    Queue::fake();
    inSpace($manager)->postJson('/api/v1/cga/certificate-campaign')->assertStatus(202)->assertJsonPath('data.queued', 1);
    Queue::assertPushed(RunCertificateCampaign::class, fn (RunCertificateCampaign $job): bool => $job->membershipId === $manager->id && $job->uniqueId() === $manager->organization_id.'|'.now()->year);

    // La fiche du centre devient incomplète avant le passage du Job (RG-ORG-04).
    Organization::query()->whereKey($manager->organization_id)->update(['stamp_file_id' => null]);
    app()->call([new RunCertificateCampaign($manager->id, now()->year), 'handle']);

    expect(certificatesOf($member))->toBe(0)
        ->and(ActivityLog::query()->where('action', 'documents.campaign_skipped')->where('error_code', 'cga_profile_incomplete')->where('subject_id', $member->id)->exists())->toBeTrue();
    Event::assertDispatched(CertificateCampaignFinished::class, fn (CertificateCampaignFinished $event): bool => $event->produced === 0 && $event->skipped === 1);
});

it('refuse la campagne sans dossier éligible, avec une fiche du centre incomplète, ou sans le droit de produire', function () {
    Queue::fake();
    $manager = documentAgent();
    inSpace($manager)->postJson('/api/v1/cga/certificate-campaign')->assertStatus(409)->assertJsonPath('code', 'certificate_campaign_empty');

    $incomplete = Membership::factory()->role(Role::CgaManager)->create();
    validatedMember($incomplete);
    inSpace($incomplete)->postJson('/api/v1/cga/certificate-campaign')->assertStatus(409)->assertJsonPath('code', 'cga_profile_incomplete');

    foreach ([Role::CgaTrainer, Role::CgaBillingOfficer] as $role) {
        $agent = documentAgent($role, $manager->organization_id);
        inSpace($agent)->getJson('/api/v1/cga/certificate-campaign')->assertForbidden();
        inSpace($agent)->postJson('/api/v1/cga/certificate-campaign')->assertForbidden();
    }
    inSpace(Membership::factory()->role(Role::CncgaPresident)->create())->getJson('/api/v1/cga/certificate-campaign')->assertForbidden();
    Queue::assertNothingPushed();
});
