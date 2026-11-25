<?php

namespace App\Domain\Platform\Policies;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Platform\Support\SettingsCatalog;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Paramètres de la plateforme et du réseau (02-TRD §7.5, PRD §3.3 « Paramétrer les règles du réseau ») :
 * au CNCGA avec network_settings.manage (et le droit propre de chaque clé), à l'administrateur de la
 * plateforme avec platform.settings.manage. Les valeurs propres d'un CGA ne passent pas par ici.
 */
class SettingPolicy
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SettingsCatalog $catalog,
    ) {}

    public function viewAny(User $user, string $space): bool
    {
        return $this->inSpace($space) && $this->context->allows($space === SettingsCatalog::PLATFORM ? 'platform.settings.manage' : 'network_settings.manage');
    }

    public function update(User $user, string $space, string $key): bool
    {
        $permission = $this->catalog->permission($space, $key);

        return $this->viewAny($user, $space) && $this->catalog->has($space, $key) && $permission !== null && $this->context->allows($permission);
    }

    private function inSpace(string $space): bool
    {
        return $this->context->has()
            && $this->context->memberId() === null
            && $this->context->organizationType() === ($space === SettingsCatalog::PLATFORM ? OrganizationType::Platform : OrganizationType::Cncga);
    }
}
