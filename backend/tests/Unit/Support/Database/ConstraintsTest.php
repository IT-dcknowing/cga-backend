<?php

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Users\Enums\Role;
use App\Support\Database\Constraints;

it('accepte un identifiant SQL en minuscules', function () {
    expect(Constraints::identifier('memberships_role_check'))->toBe('memberships_role_check');
});

it('refuse un identifiant SQL qui pourrait injecter du code', function (string $name) {
    Constraints::identifier($name);
})->throws(InvalidArgumentException::class)->with([
    'users; DROP TABLE users', 'Users', '1table', 'nom avec espace', '', str_repeat('a', 64),
]);

it('rattache les rôles du CNCGA, des CGA et des adhérents à leur type d\'organisation', function () {
    expect(Role::CncgaDues->organizationType())->toBe(OrganizationType::Cncga)
        ->and(Role::CgaTrainer->organizationType())->toBe(OrganizationType::Cga)
        ->and(Role::MemberUser->organizationType())->toBe(OrganizationType::Cga)
        ->and(Role::PlatformAdmin->organizationType())->toBe(OrganizationType::Platform)
        ->and(Role::MemberUser->isMemberRole())->toBeTrue()
        ->and(Role::CgaManager->isMemberRole())->toBeFalse();
});
