<?php

namespace App\Domain\Concerns\Http\Controllers;

use App\Domain\Concerns\Actions\ChangeConcernStatus;
use App\Domain\Concerns\Actions\PostConcernMessage;
use App\Domain\Concerns\Actions\ReopenConcern;
use App\Domain\Concerns\Actions\SubmitConcern;
use App\Domain\Concerns\Actions\TransferConcern;
use App\Domain\Concerns\Enums\ConcernLevel;
use App\Domain\Concerns\Enums\ConcernSide;
use App\Domain\Concerns\Enums\ConcernStatus;
use App\Domain\Concerns\Http\Requests\ConcernIndicatorsRequest;
use App\Domain\Concerns\Http\Requests\ConcernMessageRequest;
use App\Domain\Concerns\Http\Requests\ConcernStatusRequest;
use App\Domain\Concerns\Http\Requests\ListConcernsRequest;
use App\Domain\Concerns\Http\Requests\ReopenConcernRequest;
use App\Domain\Concerns\Http\Requests\ShowConcernRequest;
use App\Domain\Concerns\Http\Requests\StoreConcernRequest;
use App\Domain\Concerns\Http\Requests\TransferConcernRequest;
use App\Domain\Concerns\Http\Resources\ConcernResource;
use App\Domain\Concerns\Models\Concern;
use App\Domain\Concerns\Support\ConcernAccess;
use App\Domain\Concerns\Support\ConcernIndicators;
use App\Domain\Members\Enums\Portfolio;
use App\Support\Config\SettingsService;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * /api/v1/cga/concerns : préoccupations dans l'espace du CGA (/cga/preoccupations, F-PRE-02 à 04) — onglets
 * « Reçues » (demandes des adhérents, traitées par les agents) et « Envoyées au CNCGA » (« Écrire au
 * CNCGA »), fil, réponse et note interne, statut, réouverture d'une demande au CNCGA, transfert au CNCGA,
 * tableau des délais du centre.
 */
class CgaConcernController extends BaseApiController
{
    use PresentsConcerns;

    public function __construct(
        private readonly TenantContext $context,
        private readonly ConcernAccess $access,
    ) {}

    public function index(ListConcernsRequest $request, SettingsService $settings): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($request, $settings): AnonymousResourceCollection {
            $level = $request->requestedLevel();
            $received = $level === ConcernLevel::MemberToCga;
            $staleSince = CarbonImmutable::now()->subDays((int) $settings->get('concerns.stale_days', $this->context->organizationId()));
            $query = Concern::query()
                ->with(['author', 'member', 'transferredTo'])
                ->where('level', $level)
                ->when($received && ! $this->access->seesCabinet(), static fn (Builder $concerns) => $concerns->whereHas('member', static fn (Builder $member) => $member->where('portfolio', Portfolio::Cga)));

            $concerns = QueryBuilder::for($query, $request->listQuery())
                ->allowedFilters(
                    AllowedFilter::exact('status'),
                    AllowedFilter::exact('category'),
                    AllowedFilter::exact('member_id'),
                    // Onglet déjà appliqué ci-dessus.
                    AllowedFilter::callback('level', static fn (Builder $concerns): Builder => $concerns),
                    AllowedFilter::callback('awaiting', static fn (Builder $concerns, mixed $value) => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? self::awaiting($concerns) : $concerns),
                    AllowedFilter::callback('stale', static fn (Builder $concerns, mixed $value) => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? self::awaiting($concerns)->where('awaiting_reply_since', '<=', $staleSince) : $concerns),
                )
                ->allowedSorts(...$request->sorts())
                ->defaultSort('-last_activity_at')
                ->paginate($request->perPage($settings), ['*'], 'page', $request->page());
            $side = $received ? ConcernSide::Handler : ConcernSide::Requester;

            return ConcernResource::collection($concerns->through(
                static fn (Concern $concern): ConcernResource => (new ConcernResource($concern))->viewedFrom(ConcernResource::SPACE_CGA, $side),
            ));
        }, action: 'cga_concerns.index');
    }

    public function store(StoreConcernRequest $request, SubmitConcern $submit): JsonResponse
    {
        return $this->handle(function () use ($request, $submit): JsonResponse {
            $concern = $submit->execute(ConcernLevel::CgaToCncga, $request->category(), $request->subject(), $request->body(), $request->attachments(), $this->context->membership());
            $this->logSubject($concern);

            return $this->present($concern, ConcernResource::SPACE_CGA)->response()->setStatusCode(201);
        }, action: 'cga_concerns.store');
    }

    public function show(ShowConcernRequest $request, Concern $concern): ConcernResource|JsonResponse
    {
        return $this->handle(fn (): ConcernResource => $this->present($concern, ConcernResource::SPACE_CGA), action: 'cga_concerns.show');
    }

    public function reply(ConcernMessageRequest $request, Concern $concern, PostConcernMessage $post): JsonResponse
    {
        return $this->handle(function () use ($request, $concern, $post): JsonResponse {
            $side = $this->access->side($concern) ?? ConcernSide::Requester;
            $post->execute($concern, $side, $request->body(), $request->attachments(), $request->internal(), $this->context->membership());

            return $this->present($concern->refresh(), ConcernResource::SPACE_CGA)->response()->setStatusCode(201);
        }, action: 'cga_concerns.reply');
    }

    public function status(ConcernStatusRequest $request, Concern $concern, ChangeConcernStatus $change): ConcernResource|JsonResponse
    {
        return $this->handle(fn (): ConcernResource => $this->present(
            $change->execute($concern, $request->status(), $request->reason(), $this->context->membership()),
            ConcernResource::SPACE_CGA,
        ), action: 'cga_concerns.status');
    }

    public function reopen(ReopenConcernRequest $request, Concern $concern, ReopenConcern $reopen): ConcernResource|JsonResponse
    {
        return $this->handle(fn (): ConcernResource => $this->present(
            $reopen->execute($concern, $this->context->membership()),
            ConcernResource::SPACE_CGA,
        ), action: 'cga_concerns.reopen');
    }

    public function transfer(TransferConcernRequest $request, Concern $concern, TransferConcern $transfer): ConcernResource|JsonResponse
    {
        return $this->handle(fn (): ConcernResource => $this->present(
            $transfer->execute($concern, $request->body(), $request->attachments(), $this->context->membership()),
            ConcernResource::SPACE_CGA,
        ), action: 'cga_concerns.transfer');
    }

    /** Tableau des délais des demandes des adhérents du centre, les deux portefeuilles (F-PRE-04). */
    public function indicators(ConcernIndicatorsRequest $request, SettingsService $settings): JsonResponse
    {
        return $this->handle(function () use ($request, $settings): JsonResponse {
            $concerns = Concern::query()
                ->where('level', ConcernLevel::MemberToCga)
                ->when($request->from(), static fn (Builder $query, CarbonImmutable $from) => $query->where('created_at', '>=', $from))
                ->when($request->until(), static fn (Builder $query, CarbonImmutable $until) => $query->where('created_at', '<', $until));
            $days = (int) $settings->get('concerns.stale_days', $this->context->organizationId());

            return response()->json(['data' => [
                'period' => ['from' => $request->validated('from'), 'to' => $request->validated('to')],
                ...ConcernIndicators::summary($concerns),
                'stale' => self::awaiting(Concern::query()->where('level', ConcernLevel::MemberToCga))->where('awaiting_reply_since', '<=', CarbonImmutable::now()->subDays($days))->count(),
                'stale_days' => $days,
            ]]);
        }, action: 'cga_concerns.indicators');
    }

    /**
     * Demandes qui attendent une réponse de qui les traite.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $concerns
     * @return Builder<TModel>
     */
    private static function awaiting(Builder $concerns): Builder
    {
        return $concerns->whereIn('status', [ConcernStatus::Open, ConcernStatus::InReview])->whereNotNull('awaiting_reply_since');
    }
}
