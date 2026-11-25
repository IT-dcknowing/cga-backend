<?php

use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\DocumentVerification;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Documents\Support\IpPrefix;
use App\Support\Config\SettingsService;
use Illuminate\Testing\TestResponse;

/*
 * Page publique de vérification (06-IMPLEMENTATION-PLAN étape 3.6, F-DOC-04, RG-DOC-09, 02-TRD §6.6) :
 * sans compte, les cinq informations seulement, chaque consultation enregistrée avec l'adresse IP
 * tronquée, nombre de consultations par minute limité.
 */

function verify(string $code, string $ip = '196.47.128.77'): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])->withHeader('User-Agent', 'Lecteur QR')->getJson("/api/v1/public/verify/{$code}");
}

it('RG-DOC-09 affiche sans compte les cinq informations d\'un document valide et enregistre la consultation', function () {
    $agent = documentAgent();
    $member = validatedMember($agent, ['company_name' => 'ETS VÉRIFIÉE']);
    $document = GeneratedDocument::factory()->create(['member_id' => $member->id, 'type' => DocumentType::MembershipCertificate, 'number' => 'ATT-1', 'issued_at' => '2026-03-02 10:00:00']);

    $response = verify((string) $document->verification_token)->assertOk()
        ->assertExactJson(['data' => [
            'status' => 'valid',
            'status_label' => DocumentStatus::Valid->label(),
            'cancelled_on' => null,
            'type' => 'membership_certificate',
            'type_label' => DocumentType::MembershipCertificate->label(),
            'issued_on' => '2026-03-02',
            'issuer' => $agent->organization->name,
            'member_name' => 'ETS VÉRIFIÉE',
            'membership_number' => $member->membership_number,
        ]]);

    $verification = DocumentVerification::query()->sole();
    expect($verification->document_id)->toBe($document->id)
        ->and($verification->ip_prefix)->toBe('196.47.128.0/24')
        ->and($verification->user_agent)->toBe('Lecteur QR')
        ->and($response->json())->not->toHaveKey('data.id');

    $log = ActivityLog::query()->where('action', 'documents.verify')->sole();
    expect($log->actor_type)->toBe('public')
        ->and($log->actor_label)->toBe('public:verification')
        ->and($log->route)->toBe('/api/v1/public/verify/{verificationCode}')
        ->and(json_encode($log->params))->not->toContain((string) $document->verification_token)
        ->and(AuditLog::query()->where('event', 'tenancy_bypass')->exists())->toBeTrue();
});

it('RG-DOC-09 montre un document annulé avec sa date', function () {
    $document = GeneratedDocument::factory()->create(['status' => DocumentStatus::Cancelled, 'cancelled_at' => '2026-05-04 09:00:00', 'cancel_reason' => 'Radiation validée.']);

    verify((string) $document->verification_token)->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.cancelled_on', '2026-05-04')
        ->assertJsonMissingPath('data.cancel_reason');
});

it('RG-DOC-09 répond 404 sans distinction à un code inconnu ou à un document jamais émis', function () {
    foreach ([DocumentStatus::Generating, DocumentStatus::Failed] as $status) {
        $document = GeneratedDocument::factory()->create(['status' => $status, 'issued_at' => null]);
        verify((string) $document->verification_token)->assertNotFound()->assertJsonPath('code', 'not_found');
    }

    verify('code-inconnu')->assertNotFound()->assertJsonPath('code', 'not_found');
    $this->getJson('/api/v1/public/verify/'.str_repeat('a', 65))->assertNotFound();
    $this->getJson('/api/v1/public/verify/code%20avec%20espace')->assertNotFound();
    expect(DocumentVerification::query()->count())->toBe(0);
});

it('RG-DOC-09 limite les consultations par minute et par adresse (rate_limit.verification)', function () {
    app(SettingsService::class)->set('rate_limit.verification', ['max_attempts' => 2, 'decay_minutes' => 1]);
    $code = (string) GeneratedDocument::factory()->create()->verification_token;

    verify($code)->assertOk();
    verify('code-inconnu')->assertNotFound();
    verify($code)->assertStatus(429)->assertJsonPath('code', 'too_many_attempts')->assertHeader('Retry-After');
    verify($code, '196.47.129.1')->assertOk();

    expect(ActivityLog::query()->where('action', 'documents.verify')->where('result', 'throttled')->exists())->toBeTrue();

    $this->travel(61)->seconds();
    verify($code)->assertOk();
});

it('donne au CGA l\'adresse du QR code d\'un document émis, jamais d\'un document en production', function () {
    $agent = documentAgent();
    $member = validatedMember($agent);
    $issued = GeneratedDocument::factory()->create(['member_id' => $member->id]);
    $generating = GeneratedDocument::factory()->create(['member_id' => $member->id, 'status' => DocumentStatus::Generating, 'issued_at' => null]);

    inSpace($agent)->getJson("/api/v1/cga/documents/{$issued->id}")->assertOk()
        ->assertJsonPath('data.verification_url', config('documents.verification_url').'/'.$issued->verification_token);
    inSpace($agent)->getJson("/api/v1/cga/documents/{$generating->id}")->assertOk()->assertJsonPath('data.verification_url', null);
});

it('RG-DOC-09 tronque l\'adresse IP au réseau (IPv4 /24, IPv6 /48), jamais l\'adresse complète', function () {
    $prefix = app(IpPrefix::class);

    expect($prefix->of('10.20.30.40'))->toBe('10.20.30.0/24')
        ->and($prefix->of('2001:db8:85a3:8d3:1319:8a2e:370:7348'))->toBe('2001:db8:85a3::/48')
        ->and($prefix->of('pas-une-adresse'))->toBeNull()
        ->and($prefix->of(null))->toBeNull();

    config(['documents.verification_ip_prefix.ipv4' => 16]);
    expect($prefix->of('10.20.30.40'))->toBe('10.20.0.0/16');
});
