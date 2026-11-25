<?php

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Support\Logging\RequestContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

/*
 * Journal des modifications (RG-SEC-05, 02-TRD §6.5) : avant / après de chaque champ, auteur,
 * organisation, requête ; jamais de secret en clair.
 */

function logsFor(object $subject, string $event): AuditLog
{
    return AuditLog::query()->where('subject_type', $subject::class)->where('subject_id', (string) $subject->getKey())->where('event', $event)->sole();
}

it('journalise une création avec chaque champ', function () {
    $organization = Organization::factory()->create(['name' => 'CGA DC-KNOWING']);

    $log = logsFor($organization, 'created');
    expect($log->changes['name'])->toBe([null, 'CGA DC-KNOWING'])
        ->and($log->changes)->not->toHaveKeys(['created_at', 'updated_at'])
        ->and($log->organization_id)->toBe($organization->id);
});

it('journalise une modification avec l\'ancienne et la nouvelle valeur des seuls champs changés', function () {
    $organization = Organization::factory()->create(['city' => 'Abidjan']);

    $organization->city = 'Bouaké';
    $organization->save();

    expect(logsFor($organization, 'updated')->changes)->toBe(['city' => ['Abidjan', 'Bouaké']]);
});

it('n\'écrit rien quand une sauvegarde ne change aucun champ', function () {
    $organization = Organization::factory()->create();

    $organization->save();

    expect(AuditLog::query()->where('event', 'updated')->exists())->toBeFalse();
});

it('journalise une suppression avec les anciennes valeurs', function () {
    $membership = Membership::factory()->create();
    $id = $membership->id;

    $membership->delete();

    $log = AuditLog::query()->where('subject_id', $id)->where('event', 'deleted')->sole();
    expect($log->changes['role'])->toBe(['cga_accountant', null]);
});

it('ne garde jamais un mot de passe ou un secret en clair', function () {
    $user = User::factory()->create(['password' => 'secret-de-test']);
    $user->password = 'autre-secret';
    $user->save();

    $created = logsFor($user, 'created');
    $updated = logsFor($user, 'updated');
    expect($created->changes['password'])->toBe([null, AuditLogger::REDACTED])
        ->and($updated->changes['password'])->toBe([AuditLogger::REDACTED, AuditLogger::REDACTED])
        ->and(json_encode([$created->changes, $updated->changes]))->not->toContain('secret');
});

it('masque aussi les champs de logs.redacted_fields', function () {
    $log = app(AuditLogger::class)->record('essai', changes: ['token' => ['a', 'b'], 'nom' => ['A', 'B']]);

    expect($log->changes)->toBe(['token' => [AuditLogger::REDACTED, AuditLogger::REDACTED], 'nom' => ['A', 'B']]);
});

it('retient l\'auteur, le rattachement actif et l\'organisation de l\'espace', function () {
    $membership = Membership::factory()->create();
    $this->actingAs($membership->user);
    app(TenantContext::class)->set($membership);

    $log = app(AuditLogger::class)->record('export', context: ['liste' => 'adhérents']);

    expect($log->user_id)->toBe($membership->user_id)
        ->and($log->membership_id)->toBe($membership->id)
        ->and($log->organization_id)->toBe($membership->organization_id)
        ->and($log->context)->toBe(['liste' => 'adhérents'])
        ->and($log->subject_type)->toBeNull();
});

it('retient l\'identifiant de la requête, l\'adresse IP et le navigateur', function () {
    $request = Request::create('/api/v1/essai', server: ['REMOTE_ADDR' => '10.1.2.3', 'HTTP_USER_AGENT' => 'Navigateur de test']);
    $request->attributes->set(RequestContext::REQUEST_ID_ATTRIBUTE, '01JREQUESTIDENTIFIER000000');
    app()->instance('request', $request);

    $log = app(AuditLogger::class)->record('essai');

    expect($log->request_id)->toBe('01JREQUESTIDENTIFIER000000')
        ->and($log->ip)->toBe('10.1.2.3')
        ->and($log->user_agent)->toBe('Navigateur de test');
});
