<?php

namespace App\Domain\Announcements\Policies;

use App\Domain\Announcements\Models\Announcement;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Annonces (PRD M12, matrice §3.3) : le CNCGA publie avec announcements.network.publish (Présidente,
 * gestionnaire, responsable des formations, F-COM-01), un CGA avec announcements.cga.publish (gérant,
 * expert-comptable, F-COM-02) ; chacun relit les siennes. Les agents des CGA et les adhérents (accès actif)
 * lisent les annonces qu'ils reçoivent.
 */
class AnnouncementPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function publish(User $user): bool
    {
        if (! $this->context->has() || $this->context->memberId() !== null) {
            return false;
        }

        return match ($this->context->organizationType()) {
            OrganizationType::Cncga => $this->context->allows('announcements.network.publish'),
            OrganizationType::Cga => $this->context->allows('announcements.cga.publish'),
            default => false,
        };
    }

    public function view(User $user, Announcement $announcement): bool
    {
        return $this->publish($user) && $announcement->organization_id === $this->context->organizationId();
    }

    /** Fil des annonces reçues : agent d'un CGA, ou accès adhérent actif. */
    public function receive(User $user): bool
    {
        return $this->context->has()
            && $this->context->organizationType() === OrganizationType::Cga
            && $this->context->membership()->status === MembershipStatus::Active;
    }
}
