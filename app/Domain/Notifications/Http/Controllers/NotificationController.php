<?php

namespace App\Domain\Notifications\Http\Controllers;

use App\Domain\Notifications\Http\Requests\ListNotificationsRequest;
use App\Domain\Notifications\Http\Resources\NotificationResource;
use App\Domain\Users\Models\User;
use App\Support\Config\SettingsService;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * /api/v1/me/notifications : cloche de notifications (PRD M13, 6.6) — notifications de l'utilisateur
 * dans son espace actif, les plus récentes d'abord, nombre de non lues ; marquer une notification ou
 * toutes comme lues. Une notification d'un autre espace ou d'un autre utilisateur répond 404.
 */
class NotificationController extends BaseApiController
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(ListNotificationsRequest $request, SettingsService $settings): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($request, $settings): AnonymousResourceCollection {
            $notifications = $this->own()
                ->when($request->unreadOnly(), static fn (Builder $query) => $query->whereNull('read_at'))
                ->latest()
                ->paginate($request->perPage($settings), ['*'], 'page', $request->page());

            return NotificationResource::collection($notifications)->additional(['meta' => ['unread_count' => $this->own()->whereNull('read_at')->count()]]);
        }, action: 'notifications.index');
    }

    public function read(string $notificationId): NotificationResource|JsonResponse
    {
        return $this->handle(function () use ($notificationId): NotificationResource {
            // Identifiant reçu vérifié avant toute requête (les notifications portent un UUID).
            $notification = (Str::isUuid($notificationId) ? $this->own()->whereKey($notificationId)->first() : null) ?? throw new NotFoundHttpException;
            Gate::authorize('update', $notification);
            $notification->markAsRead();

            return new NotificationResource($notification);
        }, action: 'notifications.read');
    }

    public function readAll(): JsonResponse
    {
        return $this->handle(function (): JsonResponse {
            Gate::authorize('viewAny', DatabaseNotification::class);
            $count = $this->own()->whereNull('read_at')->update(['read_at' => now()]);

            return response()->json(['data' => ['marked' => $count]]);
        }, action: 'notifications.read_all');
    }

    /**
     * Notifications de l'utilisateur connecté dans son espace actif.
     *
     * @return MorphMany<DatabaseNotification, User>
     */
    private function own(): MorphMany
    {
        Gate::authorize('viewAny', DatabaseNotification::class);

        return $this->currentUser()->notifications()->where('data->membership_id', $this->context->membership()->id);
    }
}
