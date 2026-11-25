<?php

namespace App\Domain\Announcements\Http\Controllers;

use App\Domain\Announcements\Actions\MarkAnnouncementRead;
use App\Domain\Announcements\Http\Requests\ListAnnouncementsRequest;
use App\Domain\Announcements\Http\Requests\ReadAnnouncementRequest;
use App\Domain\Announcements\Http\Resources\AnnouncementResource;
use App\Domain\Announcements\Models\Announcement;
use App\Domain\Announcements\Support\AnnouncementFeed;
use App\Support\Config\SettingsService;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * /api/v1/me/announcements : annonces reçues dans l'espace actif — pour un agent, celles du CNCGA à son CGA ; pour un
 * adhérent, « Message de mon CGA » et les annonces du réseau (F-COM-01, 02) — lues ou non, nombre de non lues ;
 * lecture d'une annonce.
 */
class MyAnnouncementController extends BaseApiController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AnnouncementFeed $feed,
    ) {}

    public function index(ListAnnouncementsRequest $request, SettingsService $settings): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($request, $settings): AnonymousResourceCollection {
            $userId = $this->context->userId();
            /** @var array{0: LengthAwarePaginator<int, Announcement>, 1: int} $result */
            $result = Tenancy::bypass(function () use ($request, $settings, $userId): array {
                $read = static fn (Builder $reads) => $reads->where('user_id', $userId);
                $announcements = QueryBuilder::for($this->feed->received($this->context->membership())->with(['organization', 'attachment'])->withExists(['reads as read' => $read]), $request->listQuery())
                    ->allowedFilters(AllowedFilter::callback('unread', static fn (Builder $query, mixed $unread) => filter_var($unread, FILTER_VALIDATE_BOOLEAN) ? $query->whereDoesntHave('reads', $read) : $query))
                    ->allowedSorts(...$request->sorts())
                    ->defaultSort('-published_at')
                    ->paginate($request->perPage($settings), ['*'], 'page', $request->page());

                return [$announcements, $this->feed->received($this->context->membership())->whereDoesntHave('reads', $read)->count()];
            }, 'Annonces : annonces reçues dans l\'espace actif.');

            return AnnouncementResource::collection($result[0])->additional(['meta' => ['unread' => $result[1]]]);
        }, action: 'my_announcements.index');
    }

    /**
     * @throws NotFoundHttpException annonce qui n'est pas reçue dans l'espace actif
     */
    public function read(ReadAnnouncementRequest $request, string $announcementId, MarkAnnouncementRead $mark): JsonResponse
    {
        return $this->handle(function () use ($announcementId, $mark): JsonResponse {
            $announcement = Str::isUlid($announcementId)
                ? Tenancy::bypass(fn (): ?Announcement => $this->feed->received($this->context->membership())->find($announcementId), 'Annonces : annonce reçue dans l\'espace actif.')
                : null;
            if ($announcement === null) {
                throw new NotFoundHttpException;
            }
            $read = $mark->execute($announcement, $this->currentUser());

            return response()->json(['data' => ['id' => $announcement->id, 'read' => true, 'read_at' => $read->read_at->toIso8601String()]]);
        }, action: 'my_announcements.read');
    }
}
