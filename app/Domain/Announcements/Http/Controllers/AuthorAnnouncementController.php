<?php

namespace App\Domain\Announcements\Http\Controllers;

use App\Domain\Announcements\Actions\PublishAnnouncement;
use App\Domain\Announcements\Http\Requests\AuthorAnnouncementRequest;
use App\Domain\Announcements\Http\Requests\ListAnnouncementsRequest;
use App\Domain\Announcements\Http\Requests\PublishAnnouncementRequest;
use App\Domain\Announcements\Http\Resources\AnnouncementResource;
use App\Domain\Announcements\Models\Announcement;
use App\Support\Config\SettingsService;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * /api/v1/network/announcements (/cncga/communications, F-COM-01) et /api/v1/cga/announcements (onglet Annonces de
 * /cga/communications, F-COM-02) : les annonces de l'auteur, « + Nouvelle annonce » publiée aussitôt
 * (« Annonce publiée à n destinataires »), le détail avec ses destinataires et ses lectures.
 */
class AuthorAnnouncementController extends BaseApiController
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(ListAnnouncementsRequest $request, SettingsService $settings): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($request, $settings): AnonymousResourceCollection {
            $announcements = QueryBuilder::for(Announcement::query()->with(['organization', 'attachment', 'targets.organization'])->withCount('reads'), $request->listQuery())
                ->allowedFilters(AllowedFilter::exact('audience'))
                ->allowedSorts(...$request->sorts())
                ->defaultSort('-published_at')
                ->paginate($request->perPage($settings), ['*'], 'page', $request->page());

            return AnnouncementResource::collection($announcements->through(static fn (Announcement $announcement): AnnouncementResource => (new AnnouncementResource($announcement))->forAuthor()));
        }, action: 'announcements.index');
    }

    public function store(PublishAnnouncementRequest $request, PublishAnnouncement $publish): JsonResponse
    {
        return $this->handle(function () use ($request, $publish): JsonResponse {
            $announcement = $publish->execute(
                $request->announcementTitle(),
                $request->body(),
                $request->attachment(),
                $request->audience(),
                $request->targets(),
                $request->filter(),
                $request->sendEmail(),
                $this->context->membership(),
            );
            $this->logSubject($announcement);

            return $this->present($announcement)->additional(['meta' => ['recipients' => $announcement->recipients_count]])->response()->setStatusCode(201);
        }, action: 'announcements.store');
    }

    public function show(AuthorAnnouncementRequest $request, Announcement $announcement): AnnouncementResource|JsonResponse
    {
        return $this->handle(fn (): AnnouncementResource => $this->present($announcement), action: 'announcements.show');
    }

    private function present(Announcement $announcement): AnnouncementResource
    {
        return (new AnnouncementResource($announcement->load(['organization', 'attachment', 'targets.organization'])->loadCount('reads')))->forAuthor();
    }
}
