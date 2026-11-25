<?php

namespace App\Domain\Notifications\Policies;

use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Notifications dans la plateforme (PRD M13, cloche) : chacun ne voit et ne marque lues que les
 * siennes, celles de son espace actif.
 */
class NotificationPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->has();
    }

    public function update(User $user, DatabaseNotification $notification): bool
    {
        return $this->context->has()
            && $notification->notifiable_type === $user->getMorphClass()
            && (string) $notification->notifiable_id === $user->id
            && ($notification->data['membership_id'] ?? null) === $this->context->membership()->id;
    }
}
