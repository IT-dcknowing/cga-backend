<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\MissingTenantContextException;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenancyViolationException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\TenantNote;

/*
 * Cloisonnement des données par organisation (02-TRD §6.3, RG-SEC-02) : scope global, organisation
 * fixée par le serveur, contournement explicite et journalisé.
 */

beforeEach(function () {
    TenantNote::createTable();

    $this->cgaA = Membership::factory()->create();
    $this->cgaB = Membership::factory()->create();
    DB::table('tenant_notes')->insert([
        ['organization_id' => $this->cgaA->organization_id, 'body' => 'note du CGA A'],
        ['organization_id' => $this->cgaB->organization_id, 'body' => 'note du CGA B'],
    ]);
});

function enterSpace(Membership $membership): void
{
    app(TenantContext::class)->set($membership);
}

it('refuse de lire une donnée cloisonnée sans espace actif', function () {
    TenantNote::query()->get();
})->throws(MissingTenantContextException::class);

it('ne montre que les données de l\'organisation active', function () {
    enterSpace($this->cgaA);

    expect(TenantNote::query()->pluck('body')->all())->toBe(['note du CGA A'])
        ->and(TenantNote::query()->find(TenantNote::query()->withoutGlobalScopes()->where('body', 'note du CGA B')->value('id')))->toBeNull();
});

it('renseigne l\'organisation active à la création', function () {
    enterSpace($this->cgaA);

    $note = TenantNote::query()->create(['body' => 'nouvelle']);

    expect($note->organization_id)->toBe($this->cgaA->organization_id);
});

it('refuse une création dans une autre organisation que l\'organisation active', function () {
    enterSpace($this->cgaA);

    TenantNote::query()->create(['body' => 'intrusion', 'organization_id' => $this->cgaB->organization_id]);
})->throws(TenancyViolationException::class);

it('refuse de changer l\'organisation d\'une donnée', function () {
    enterSpace($this->cgaA);
    $note = TenantNote::query()->firstOrFail();

    $note->organization_id = $this->cgaB->organization_id;
    $note->save();
})->throws(TenancyViolationException::class);

it('refuse une création sans espace actif', function () {
    TenantNote::query()->create(['body' => 'orpheline']);
})->throws(MissingTenantContextException::class);

it('lit toutes les organisations dans un contournement, journalisé avec son motif', function () {
    $bodies = Tenancy::bypass(fn () => TenantNote::query()->orderBy('body')->pluck('body')->all(), reason: 'statistiques agrégées');

    expect($bodies)->toBe(['note du CGA A', 'note du CGA B']);
    $log = AuditLog::query()->where('event', 'tenancy_bypass')->sole();
    expect($log->context)->toBe(['reason' => 'statistiques agrégées']);
});

it('exige une organisation explicite pour créer dans un contournement', function () {
    expect(fn () => Tenancy::bypass(fn () => TenantNote::query()->create(['body' => 'x']), reason: 'tâche système'))
        ->toThrow(TenancyViolationException::class);

    $note = Tenancy::bypass(fn () => TenantNote::query()->create(['body' => 'x', 'organization_id' => $this->cgaB->organization_id]), reason: 'tâche système');
    expect($note->organization_id)->toBe($this->cgaB->organization_id);
});

it('refuse un contournement sans motif', function () {
    Tenancy::bypass(fn () => null, reason: '  ');
})->throws(InvalidArgumentException::class);

it('referme le contournement même si le traitement échoue', function () {
    expect(fn () => Tenancy::bypass(fn () => throw new RuntimeException('panne'), reason: 'essai'))->toThrow(RuntimeException::class)
        ->and(Tenancy::isBypassed())->toBeFalse()
        ->and(fn () => TenantNote::query()->get())->toThrow(MissingTenantContextException::class);
});

it('expose l\'espace actif : organisation, type, rôle, adhérent et utilisateur', function () {
    $context = app(TenantContext::class);
    expect($context->has())->toBeFalse();

    enterSpace($this->cgaA);

    expect($context->has())->toBeTrue()
        ->and($context->organizationId())->toBe($this->cgaA->organization_id)
        ->and($context->organization()->is($this->cgaA->organization))->toBeTrue()
        ->and($context->organizationType())->toBe($this->cgaA->organization->type)
        ->and($context->role())->toBe($this->cgaA->role)
        ->and($context->memberId())->toBeNull()
        ->and($context->userId())->toBe($this->cgaA->user_id)
        ->and($context->allows('members.update'))->toBeTrue();

    $context->clear();
    expect($context->has())->toBeFalse();
});

it('relie une donnée cloisonnée à son organisation', function () {
    enterSpace($this->cgaA);

    expect(TenantNote::query()->firstOrFail()->organization->is($this->cgaA->organization))->toBeTrue();
});

it('charge l\'espace actif avant de chercher l\'objet de la route, et seulement dans cet espace', function () {
    Route::middleware(['api', 'auth', 'tenant'])->get('api/v1/essai-note/{note}', fn (TenantNote $note) => ['body' => $note->body]);
    $noteA = TenantNote::query()->withoutGlobalScopes()->where('body', 'note du CGA A')->value('id');
    $noteB = TenantNote::query()->withoutGlobalScopes()->where('body', 'note du CGA B')->value('id');

    inSpace($this->cgaA)->getJson("/api/v1/essai-note/{$noteA}")->assertOk()->assertJsonPath('body', 'note du CGA A');
    inSpace($this->cgaA)->getJson("/api/v1/essai-note/{$noteB}")->assertNotFound();
});
