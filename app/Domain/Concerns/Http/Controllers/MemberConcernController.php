<?php

namespace App\Domain\Concerns\Http\Controllers;

use App\Domain\Concerns\Actions\PostConcernMessage;
use App\Domain\Concerns\Actions\ReopenConcern;
use App\Domain\Concerns\Actions\RequestDocument;
use App\Domain\Concerns\Actions\SubmitConcern;
use App\Domain\Concerns\Enums\ConcernLevel;
use App\Domain\Concerns\Enums\ConcernSide;
use App\Domain\Concerns\Http\Requests\ConcernMessageRequest;
use App\Domain\Concerns\Http\Requests\DocumentRequestRequest;
use App\Domain\Concerns\Http\Requests\ListConcernsRequest;
use App\Domain\Concerns\Http\Requests\ReopenConcernRequest;
use App\Domain\Concerns\Http\Requests\ShowConcernRequest;
use App\Domain\Concerns\Http\Requests\StoreConcernRequest;
use App\Domain\Concerns\Http\Resources\ConcernResource;
use App\Domain\Concerns\Models\Concern;
use App\Support\Config\SettingsService;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * /api/v1/member/concerns : « Mes messages » de l'adhérent (/adherent/messages, F-PRE-01, 02) — ses
 * demandes à son CGA, « Nouvelle demande », fil et réponse, « Rouvrir la demande » ; « Demander un
 * document » (F-DOC-06). Il ne voit ni les notes internes ni la demande transmise au CNCGA.
 */
class MemberConcernController extends BaseApiController
{
    use PresentsConcerns;

    public function __construct(private readonly TenantContext $context) {}

    public function index(ListConcernsRequest $request, SettingsService $settings): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($request, $settings): AnonymousResourceCollection {
            $concerns = QueryBuilder::for(Concern::query()->with('author')->where('level', ConcernLevel::MemberToCga)->where('member_id', $this->context->memberId()), $request->listQuery())
                ->allowedFilters(AllowedFilter::exact('status'))
                ->allowedSorts(...$request->sorts())
                ->defaultSort('-last_activity_at')
                ->paginate($request->perPage($settings), ['*'], 'page', $request->page());

            return ConcernResource::collection($concerns->through(
                static fn (Concern $concern): ConcernResource => (new ConcernResource($concern))->viewedFrom(ConcernResource::SPACE_MEMBER, ConcernSide::Requester),
            ));
        }, action: 'member_concerns.index');
    }

    public function store(StoreConcernRequest $request, SubmitConcern $submit): JsonResponse
    {
        return $this->handle(function () use ($request, $submit): JsonResponse {
            $concern = $submit->execute(ConcernLevel::MemberToCga, $request->category(), $request->subject(), $request->body(), $request->attachments(), $this->context->membership());
            $this->logSubject($concern);

            return $this->present($concern, ConcernResource::SPACE_MEMBER)->response()->setStatusCode(201);
        }, action: 'member_concerns.store');
    }

    public function show(ShowConcernRequest $request, Concern $concern): ConcernResource|JsonResponse
    {
        return $this->handle(fn (): ConcernResource => $this->present($concern, ConcernResource::SPACE_MEMBER), action: 'member_concerns.show');
    }

    public function reply(ConcernMessageRequest $request, Concern $concern, PostConcernMessage $post): JsonResponse
    {
        return $this->handle(function () use ($request, $concern, $post): JsonResponse {
            $post->execute($concern, ConcernSide::Requester, $request->body(), $request->attachments(), false, $this->context->membership());

            return $this->present($concern->refresh(), ConcernResource::SPACE_MEMBER)->response()->setStatusCode(201);
        }, action: 'member_concerns.reply');
    }

    public function reopen(ReopenConcernRequest $request, Concern $concern, ReopenConcern $reopen): ConcernResource|JsonResponse
    {
        return $this->handle(fn (): ConcernResource => $this->present(
            $reopen->execute($concern, $this->context->membership()),
            ConcernResource::SPACE_MEMBER,
        ), action: 'member_concerns.reopen');
    }

    public function requestDocument(DocumentRequestRequest $request, RequestDocument $requestDocument): JsonResponse
    {
        return $this->handle(function () use ($request, $requestDocument): JsonResponse {
            $concern = $requestDocument->execute($request->type(), $request->body(), $this->context->membership());
            $this->logSubject($concern);

            return $this->present($concern, ConcernResource::SPACE_MEMBER)->response()->setStatusCode(201);
        }, action: 'member_concerns.request_document');
    }
}
