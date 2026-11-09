<?php

namespace App\Domain\Notifications\Policies;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Comptes d'envoi SMS, WhatsApp et e-mail (écran « Envois », F-ADM-06, RG-NOT-05) : administrateur de
 * la plateforme (platform.messaging.manage), dans l'espace de la plateforme.
 */
class MessagingProviderPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function manage(User $user): bool
    {
        return $this->context->has()
            && $this->context->organizationType() === OrganizationType::Platform
            && $this->context->allows('platform.messaging.manage');
    }
}
