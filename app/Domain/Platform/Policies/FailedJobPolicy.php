<?php

namespace App\Domain\Platform\Policies;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Files d'attente et travaux en échec (écran « Technique » de /plateforme, 02-TRD §12.3) : administrateur de la
 * plateforme (platform.queues.manage), dans l'espace de la plateforme.
 */
class FailedJobPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function manage(User $user): bool
    {
        return $this->context->has()
            && $this->context->organizationType() === OrganizationType::Platform
            && $this->context->allows('platform.queues.manage');
    }
}
