<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\RolePermission;
use App\Domain\Users\Models\User;
use App\Domain\Users\Models\UserPermissionOverride;
use App\Domain\Users\Permissions\PermissionCatalog;
use App\Domain\Users\Permissions\PermissionResolver;
use App\Domain\Users\Permissions\UnknownPermissionException;
use App\Support\Http\Exceptions\BusinessRuleException;
use App\Support\Tenancy\TenantContext;

/*
 * Droits paramétrables (RG-ROLE-01 à 07, 02-TRD §6.2) : verrou → ajustement utilisateur → surcharge
 * du CGA → réseau ; matrice par défaut du 01-PRD §3.3.
 */

function membershipAs(Role $role, ?Organization $organization = null, ?User $user = null): Membership
{
    $factory = Membership::factory()->role($role);
    $attributes = array_filter([
        'organization_id' => $organization?->id,
        'user_id' => $user?->id,
        'member_id' => $role->isMemberRole() && $organization !== null ? Member::factory()->create(['organization_id' => $organization->id])->id : null,
    ]);

    return $factory->create($attributes);
}

function networkRule(Role $role, string $permission, bool $granted, ?Organization $cga = null): RolePermission
{
    $row = new RolePermission;
    $row->organization_id = $cga?->id;
    $row->role = $role;
    $row->permission = $permission;
    $row->granted = $granted;
    $row->save();

    return $row;
}

function userOverride(Membership $membership, string $permission, bool $granted): UserPermissionOverride
{
    app(TenantContext::class)->set($membership);
    $override = new UserPermissionOverride;
    $override->membership_id = $membership->id;
    $override->permission = $permission;
    $override->granted = $granted;
    $override->updated_by = User::factory()->create()->id;
    $override->save();
    app(TenantContext::class)->clear();

    return $override;
}

function allows(Membership $membership, string $permission): bool
{
    return app(PermissionResolver::class)->allows($membership, $permission);
}

describe('catalogue', function () {
    it('nomme chaque droit en français et ne cite que des rôles existants', function () {
        $catalog = app(PermissionCatalog::class);

        foreach ($catalog->all() as $code => $entry) {
            expect($catalog->label($code))->not->toStartWith('permissions.');
            foreach ($entry['roles'] as $role) {
                expect(Role::tryFrom($role))->not->toBeNull();
            }
        }
    });

    it('RG-ROLE-04 verrouille les droits marqués 🔒 dans la matrice et le consentement bancaire', function () {
        $locked = array_keys(array_filter(app(PermissionCatalog::class)->all(), fn (array $entry): bool => $entry['locked']));

        expect($locked)->toEqualCanonicalizing([
            'organizations.suspend', 'network_users.manage', 'network_user_permissions.manage', 'network_permissions.manage',
            'cga_users.manage', 'cga_permissions.manage', 'cga_user_permissions.manage', 'members.radiation.cancel',
            'member.banking.consent',
        ]);
    });

    it('RG-SEC-01 ne donne à aucun rôle du CNCGA un droit sur les adhérents d\'un CGA', function () {
        $catalog = app(PermissionCatalog::class);
        $cncgaRoles = [Role::CncgaPresident, Role::CncgaManager, Role::CncgaDues, Role::CncgaTraining, Role::CncgaViewer];

        foreach ($catalog->codes() as $code) {
            if (str_starts_with($code, 'members.') || str_starts_with($code, 'member.')) {
                foreach ($cncgaRoles as $role) {
                    expect($catalog->grantsByDefault($role, $code))->toBeFalse();
                }
            }
        }
    });

    it('regroupe les droits par module', function () {
        expect(app(PermissionCatalog::class)->module('members.radiation.cancel'))->toBe('members');
    });

    it('refuse d\'écrire un droit inconnu (422) ou verrouillé (409)', function () {
        $catalog = app(PermissionCatalog::class);

        expect(fn () => $catalog->assertWritable('members.inconnu'))->toThrow(fn (BusinessRuleException $e) => expect([$e->errorCode, $e->status])->toBe(['unknown_permission', 422]))
            ->and(fn () => $catalog->assertWritable('members.radiation.cancel'))->toThrow(fn (BusinessRuleException $e) => expect([$e->errorCode, $e->status])->toBe(['locked_permission', 409]));

        $catalog->assertWritable('members.update');
    });

    it('signale un code absent du catalogue comme une erreur du code', function () {
        app(PermissionCatalog::class)->isLocked('members.inconnu');
    })->throws(UnknownPermissionException::class);
});

describe('matrice par défaut (RG-ROLE-01)', function () {
    it('applique la matrice validée par le Directeur', function (Role $role, string $permission, bool $granted) {
        expect(allows(membershipAs($role), $permission))->toBe($granted);
    })->with([
        'la Présidente suspend un CGA' => [Role::CncgaPresident, 'organizations.suspend', true],
        'le gestionnaire CNCGA ne suspend pas' => [Role::CncgaManager, 'organizations.suspend', false],
        'le bureau consulte le registre' => [Role::CncgaViewer, 'organizations.view', true],
        'le bureau ne modifie pas un CGA' => [Role::CncgaViewer, 'organizations.manage', false],
        'le responsable des cotisations relance' => [Role::CncgaDues, 'dues.remind', true],
        'le gérant annule une radiation' => [Role::CgaManager, 'members.radiation.cancel', true],
        'l\'expert-comptable valide une radiation' => [Role::CgaAccountantExpert, 'members.radiation.validate', true],
        'l\'expert-comptable n\'annule pas une radiation' => [Role::CgaAccountantExpert, 'members.radiation.cancel', false],
        'le comptable ne demande pas de radiation' => [Role::CgaAccountant, 'members.radiation.request', false],
        'la secrétaire inscrit un adhérent' => [Role::CgaSecretary, 'members.create', true],
        'la secrétaire ne modifie pas un adhérent' => [Role::CgaSecretary, 'members.update', false],
        'le juriste a les droits du comptable' => [Role::CgaLegal, 'members.update', true],
        'le fiscaliste a les droits du comptable' => [Role::CgaTaxAdvisor, 'obligations.manage', true],
        'le chargé des cotisations encaisse' => [Role::CgaBillingOfficer, 'member_payments.manage', true],
        'le chargé des cotisations consulte les adhérents' => [Role::CgaBillingOfficer, 'members.view', true],
        'le chargé des cotisations n\'inscrit pas' => [Role::CgaBillingOfficer, 'members.create', false],
        'le formateur gère les formations du CGA' => [Role::CgaTrainer, 'trainings.cga.manage', true],
        'seul le gérant voit les apporteurs avec l\'expert' => [Role::CgaAccountant, 'members.referrers.view', false],
        'l\'accès principal donne le consentement bancaire' => [Role::MemberOwner, 'member.banking.consent', true],
        'l\'accès secondaire ne le donne pas' => [Role::MemberUser, 'member.banking.consent', false],
        'seul l\'administrateur gère les droits du réseau' => [Role::PlatformAdmin, 'network_permissions.manage', true],
        'la Présidente ne gère pas les droits du réseau' => [Role::CncgaPresident, 'network_permissions.manage', false],
    ]);
});

describe('ordre de résolution', function () {
    it('applique le paramétrage réseau à la place de la matrice', function () {
        networkRule(Role::CgaAccountant, 'members.update', false);

        expect(allows(membershipAs(Role::CgaAccountant), 'members.update'))->toBeFalse();
    });

    it('RG-ROLE-03 fait primer la surcharge du CGA sur le réseau, pour ce seul CGA', function () {
        $cga = Organization::factory()->create();
        networkRule(Role::CgaAccountant, 'members.radiation.request', false);
        networkRule(Role::CgaAccountant, 'members.radiation.request', true, $cga);

        expect(allows(membershipAs(Role::CgaAccountant, $cga), 'members.radiation.request'))->toBeTrue()
            ->and(allows(membershipAs(Role::CgaAccountant), 'members.radiation.request'))->toBeFalse();
    });

    it('RG-ROLE-03 n\'applique aucune surcharge du CGA aux droits du gérant', function () {
        $cga = Organization::factory()->create();
        networkRule(Role::CgaManager, 'members.update', false, $cga);

        expect(allows(membershipAs(Role::CgaManager, $cga), 'members.update'))->toBeTrue();
    });

    it('RG-ROLE-07 fait primer l\'ajustement individuel sur le profil, pour accorder comme pour retirer', function () {
        $cga = Organization::factory()->create();
        networkRule(Role::CgaAccountant, 'members.update', true, $cga);
        $withdrawn = membershipAs(Role::CgaAccountant, $cga);
        $granted = membershipAs(Role::CgaAccountant, $cga);
        userOverride($withdrawn, 'members.update', false);
        userOverride($granted, 'members.referrers.view', true);

        expect(allows($withdrawn, 'members.update'))->toBeFalse()
            ->and(allows($granted, 'members.referrers.view'))->toBeTrue()
            ->and(allows($granted, 'members.update'))->toBeTrue();
    });

    it('RG-ROLE-04 ne laisse jamais accorder une permission verrouillée, à aucun niveau', function () {
        $cga = Organization::factory()->create();
        $accountant = membershipAs(Role::CgaAccountant, $cga);
        networkRule(Role::CgaAccountant, 'members.radiation.cancel', true);
        networkRule(Role::CgaAccountant, 'members.radiation.cancel', true, $cga);
        userOverride($accountant, 'members.radiation.cancel', true);

        expect(allows($accountant, 'members.radiation.cancel'))->toBeFalse();
    });

    it('RG-ROLE-04 ne laisse jamais retirer une permission verrouillée', function () {
        $manager = membershipAs(Role::CgaManager);
        networkRule(Role::CgaManager, 'members.radiation.cancel', false);
        userOverride($manager, 'members.radiation.cancel', false);

        expect(allows($manager, 'members.radiation.cancel'))->toBeTrue();
    });

    it('additionne les rôles d\'un utilisateur dans une organisation', function () {
        $cncga = Organization::factory()->cncga()->create();
        $user = User::factory()->create();
        $dues = membershipAs(Role::CncgaDues, $cncga, $user);
        membershipAs(Role::CncgaTraining, $cncga, $user);

        expect(allows($dues, 'dues.remind'))->toBeTrue()
            ->and(allows($dues, 'trainings.network.manage'))->toBeTrue()
            ->and(allows($dues, 'organizations.manage'))->toBeFalse();
    });

    it('fait primer un retrait individuel sur un autre rôle qui accorde le droit', function () {
        $cncga = Organization::factory()->cncga()->create();
        $user = User::factory()->create();
        $dues = membershipAs(Role::CncgaDues, $cncga, $user);
        $training = membershipAs(Role::CncgaTraining, $cncga, $user);
        userOverride($training, 'exports.run', false);

        expect(allows($dues, 'exports.run'))->toBeFalse();
    });

    it('n\'additionne pas un rôle désactivé', function () {
        $cncga = Organization::factory()->cncga()->create();
        $user = User::factory()->create();
        $dues = membershipAs(Role::CncgaDues, $cncga, $user);
        $training = membershipAs(Role::CncgaTraining, $cncga, $user);
        $training->status = MembershipStatus::Disabled;
        $training->save();

        expect(allows($dues, 'trainings.network.manage'))->toBeFalse();
    });

    it('donne l\'origine de chaque droit : verrou, utilisateur, centre ou réseau', function () {
        $cga = Organization::factory()->create();
        $accountant = membershipAs(Role::CgaAccountant, $cga);
        networkRule(Role::CgaAccountant, 'members.radiation.request', true, $cga);
        userOverride($accountant, 'members.update', false);

        $explained = app(PermissionResolver::class)->explain($accountant);

        expect($explained['members.radiation.cancel'])->toBe(['granted' => false, 'origin' => 'locked'])
            ->and($explained['members.update'])->toBe(['granted' => false, 'origin' => 'user'])
            ->and($explained['members.radiation.request'])->toBe(['granted' => true, 'origin' => 'organization'])
            ->and($explained['members.create'])->toBe(['granted' => true, 'origin' => 'network'])
            ->and($explained['members.referrers.view'])->toBe(['granted' => false, 'origin' => 'network']);
    });

    it('signale l\'origine « centre » d\'un droit retiré par le CGA', function () {
        $cga = Organization::factory()->create();
        networkRule(Role::CgaAccountant, 'members.update', false, $cga);

        expect(app(PermissionResolver::class)->explain(membershipAs(Role::CgaAccountant, $cga))['members.update'])
            ->toBe(['granted' => false, 'origin' => 'organization']);
    });

    it('refuse un code absent du catalogue', function () {
        allows(membershipAs(Role::CgaManager), 'members.inconnu');
    })->throws(UnknownPermissionException::class);
});

describe('effet immédiat (RG-ROLE-05)', function () {
    it('garde les droits en cache et les recalcule dès qu\'un droit change', function () {
        $accountant = membershipAs(Role::CgaAccountant);
        expect(allows($accountant, 'members.update'))->toBeTrue();

        $rule = networkRule(Role::CgaAccountant, 'members.update', false);
        expect(allows($accountant, 'members.update'))->toBeFalse();

        $rule->delete();
        expect(allows($accountant, 'members.update'))->toBeTrue();
    });

    it('recalcule les droits quand un ajustement individuel change', function () {
        $accountant = membershipAs(Role::CgaAccountant);
        expect(allows($accountant, 'members.update'))->toBeTrue();

        $override = userOverride($accountant, 'members.update', false);
        expect(allows($accountant, 'members.update'))->toBeFalse();

        app(TenantContext::class)->set($accountant);
        $override->delete();
        expect(allows($accountant, 'members.update'))->toBeTrue();
    });

    it('journalise chaque modification de droit avec l\'avant et l\'après', function () {
        $rule = networkRule(Role::CgaAccountant, 'members.update', false);
        $rule->granted = true;
        $rule->save();

        $log = AuditLog::query()->where('subject_type', RolePermission::class)->where('event', 'updated')->sole();
        expect($log->changes['granted'])->toBe([false, true]);
    });
});

it('rattache une surcharge à son CGA et un ajustement à son rattachement', function () {
    $cga = Organization::factory()->create();
    $accountant = membershipAs(Role::CgaAccountant, $cga);

    $rule = networkRule(Role::CgaAccountant, 'members.update', false, $cga);
    $override = userOverride($accountant, 'members.update', true);

    expect($rule->organization->is($cga))->toBeTrue()
        ->and($override->membership->is($accountant))->toBeTrue()
        ->and($override->organization_id)->toBe($cga->id);
});
