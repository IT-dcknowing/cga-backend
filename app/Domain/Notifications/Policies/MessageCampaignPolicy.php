<?php

namespace App\Domain\Notifications\Policies;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Envois groupés d'un centre (F-COM-03) : préparés et envoyés par le gérant, l'expert-comptable et les
 * comptables (communications.bulk.send), validés au-delà du seuil par le gérant
 * (communications.bulk.approve, RG-COM-01).
 */
class MessageCampaignPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function view(User $user): bool
    {
        return $this->send($user) || $this->approve($user);
    }

    public function send(User $user): bool
    {
        return $this->inCga() && $this->context->allows('communications.bulk.send');
    }

    public function approve(User $user): bool
    {
        return $this->inCga() && $this->context->allows('communications.bulk.approve');
    }

    private function inCga(): bool
    {
        return $this->context->has() && $this->context->organizationType() === OrganizationType::Cga && $this->context->memberId() === null;
    }
}
