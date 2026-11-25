<?php

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Tables du socle (06-IMPLEMENTATION-PLAN étape 0.6, 05-BACKEND-SCHEMA §0, §2, §3, §16).
 */

/**
 * L'écriture refusée passe par un point de sauvegarde : sous PostgreSQL, une erreur annulerait
 * sinon toute la transaction du test.
 */
function rejectedByDatabase(Closure $statement): void
{
    expect(fn () => DB::transaction($statement))->toThrow(QueryException::class);
}

function newUlid(): string
{
    return strtolower((string) Str::ulid());
}

it('crée les tables du socle', function (string $table) {
    expect(Schema::hasTable($table))->toBeTrue();
})->with([
    'users', 'sessions', 'organizations', 'memberships', 'invitations', 'otp_codes', 'files',
    'audit_logs', 'settings', 'sequences', 'platform_access_logs', 'cache', 'jobs', 'failed_jobs',
]);

it('active les extensions pg_trgm, unaccent et citext', function () {
    $extensions = collect(DB::select('select extname from pg_extension'))->pluck('extname');

    expect($extensions)->toContain('pg_trgm', 'unaccent', 'citext');
});

it('identifie les tables métier par un ULID, jamais par un numéro séquentiel', function (string $table) {
    $column = DB::selectOne(
        'select data_type, character_maximum_length from information_schema.columns where table_name = ? and column_name = ?',
        [$table, 'id'],
    );

    expect($column->data_type)->toBe('character')
        ->and($column->character_maximum_length)->toBe(26);
})->with(['users', 'organizations', 'memberships', 'invitations', 'files']);

it('n\'a aucune table de jetons d\'API ni de réinitialisation par lien', function () {
    expect(Schema::hasTable('personal_access_tokens'))->toBeFalse()
        ->and(Schema::hasTable('password_reset_tokens'))->toBeFalse();
});

it('refuse un statut d\'utilisateur hors de la liste', function () {
    rejectedByDatabase(fn () => DB::table('users')->insert([
        'id' => newUlid(), 'first_name' => 'A', 'last_name' => 'B', 'phone' => '+2250700000001', 'status' => 'banni',
    ]));
});

it('refuse un type d\'organisation hors de la liste', function () {
    rejectedByDatabase(fn () => DB::table('organizations')->insert([
        'id' => newUlid(), 'type' => 'banque', 'name' => 'X', 'city' => 'Abidjan', 'phone_1' => '+2252700000000',
    ]));
});

it('refuse un rôle inconnu pour un rattachement', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    rejectedByDatabase(fn () => DB::table('memberships')->insert([
        'id' => newUlid(), 'user_id' => $user->id, 'organization_id' => $organization->id, 'role' => 'super_admin', 'status' => 'active',
    ]));
});

it('exige l\'adhérent d\'un accès adhérent, et lui seul', function () {
    $organization = Organization::factory()->create();

    rejectedByDatabase(fn () => Membership::factory()->create([
        'organization_id' => $organization->id, 'role' => Role::MemberOwner, 'member_id' => null,
    ]));
    rejectedByDatabase(fn () => Membership::factory()->create([
        'organization_id' => $organization->id, 'role' => Role::CgaAccountant, 'member_id' => newUlid(),
    ]));
});

it('n\'admet qu\'un seul CNCGA et une seule organisation plateforme', function (string $state) {
    Organization::factory()->{$state}()->create();

    rejectedByDatabase(fn () => Organization::factory()->{$state}()->create());
})->with(['cncga', 'platform']);

it('accepte une abréviation de numérotation de 2 à 6 majuscules', function (string $abbreviation) {
    expect(Organization::factory()->create(['numbering_abbreviation' => $abbreviation])->numbering_abbreviation)->toBe($abbreviation);
})->with(['DC', 'DCK', 'DCKNOW']);

it('refuse une autre abréviation de numérotation', function (string $abbreviation) {
    rejectedByDatabase(fn () => Organization::factory()->create(['numbering_abbreviation' => $abbreviation]));
})->with(['D', 'DCKNOWI', 'dck', 'DC1']);

it('identifie un compte par un téléphone unique', function () {
    User::factory()->create(['phone' => '+2250701020304']);

    rejectedByDatabase(fn () => User::factory()->create(['phone' => '+2250701020304']));
});

it('compare les adresses e-mail sans tenir compte de la casse, et admet plusieurs comptes sans e-mail', function () {
    User::factory()->create(['email' => 'gerant@dc-knowing.ci']);
    User::factory()->count(2)->create(['email' => null]);

    rejectedByDatabase(fn () => User::factory()->create(['email' => 'GERANT@DC-Knowing.ci']));
    expect(User::query()->whereNull('email')->count())->toBe(2);
});

it('ne crée pas deux fois le même rôle d\'un utilisateur dans une organisation', function () {
    $membership = Membership::factory()->create();

    rejectedByDatabase(fn () => Membership::factory()->create([
        'user_id' => $membership->user_id,
        'organization_id' => $membership->organization_id,
        'role' => $membership->role,
    ]));
});

it('garde une seule valeur par paramètre et par organisation, réseau compris', function () {
    $cga = Organization::factory()->create();
    $insert = fn (?string $organizationId) => fn () => DB::table('settings')->insert([
        'organization_id' => $organizationId, 'key' => 'files.disk_alert_percent', 'value' => '80',
    ]);

    $insert(null)();
    $insert($cga->id)();

    rejectedByDatabase($insert(null));
    rejectedByDatabase($insert($cga->id));
});

it('garde un seul compteur par organisation, clé et année, la séquence continue comprise', function () {
    $cga = Organization::factory()->create();
    $insert = fn (?int $year) => fn () => DB::table('sequences')->insert(['organization_id' => $cga->id, 'key' => 'membership', 'year' => $year]);

    $insert(null)();
    $insert(2026)();

    rejectedByDatabase($insert(null));
    rejectedByDatabase($insert(2026));
});

it('limite un code envoyé par SMS à 3 essais', function () {
    $user = User::factory()->create();
    $insert = fn (int $attempts) => fn () => DB::table('otp_codes')->insert([
        'user_id' => $user->id, 'purpose' => 'password_reset', 'target' => $user->phone,
        'code_hash' => str_repeat('a', 64), 'attempts' => $attempts, 'expires_at' => now()->addMinutes(10),
    ]);

    $insert(3)();

    rejectedByDatabase($insert(4));
});

it('accepte une organisation de chaque type', function (OrganizationType $type) {
    $organization = Organization::factory()->state(['type' => $type, 'numbering_abbreviation' => null])->create();

    expect($organization->fresh()->type)->toBe($type);
})->with(OrganizationType::cases());
