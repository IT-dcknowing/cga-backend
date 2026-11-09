<?php

namespace App\Domain\Concerns\Http\Controllers;

use App\Domain\Concerns\Actions\ChangeConcernStatus;
use App\Domain\Concerns\Actions\PostConcernMessage;
use App\Domain\Concerns\Enums\ConcernLevel;
use App\Domain\Concerns\Enums\ConcernSide;
use App\Domain\Concerns\Http\Requests\ConcernIndicatorsRequest;
use App\Domain\Concerns\Http\Requests\ConcernMessageRequest;
use App\Domain\Concerns\Http\Requests\ConcernStatusRequest;
use App\Domain\Concerns\Http\Requests\ListConcernsRequest;
use App\Domain\Concerns\Http\Requests\ShowConcernRequest;
use App\Domain\Concerns\Http\Resources\ConcernResource;
use App\Domain\Concerns\Models\Concern;
use App\Domain\Concerns\Support\ConcernIndicators;
use App\Domain\Concerns\Support\ConcernThread;
use App\Domain\Organizations\Models\Organization;
use App\Support\Config\SettingsService;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * /api/v1/network/concerns : préoccupations vues du CNCGA (/cncga/preoccupations, F-PRE-02, F-PRE-04) — les
 * demandes des CGA (contournement nommé : elles appartiennent au centre émetteur), fil, réponse et note
 * interne, statut ; onglet « Indicateurs » : chiffres des demandes des adhérents aux CGA, du seul portefeuille
 * CGA, jamais leur contenu (RG-PRE-03, RG-SEC-01).
 */
class NetworkConcernController extends BaseApiController
{
    use PresentsConcerns;

    public function __construct(private readonly TenantContext $context) {}

    public function index(ListConcernsRequest $request, SettingsService $settings): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($request, $settings): AnonymousResourceCollection {
            /** @var LengthAwarePaginator<int, Concern> $concerns */
            $concerns = Tenancy::bypass(static fn (): LengthAwarePaginator => QueryBuilder::for(Concern::query()->with(['author', 'organization'])->where('level', ConcernLevel::CgaToCncga), $request->listQuery())
                ->allowedFilters(AllowedFilter::exact('status'), AllowedFilter::exact('category'), AllowedFilter::exact('organization_id'))
                ->allowedSorts(...$request->sorts())
                ->defaultSort('-last_activity_at')
                ->paginate($request->perPage($settings), ['*'], 'page', $request->page()), 'Préoccupations : demandes des CGA lues par le CNCGA (F-PRE-02).');

            return ConcernResource::collection($concerns->through(
                static fn (Concern $concern): ConcernResource => (new ConcernResource($concern))->viewedFrom(ConcernResource::SPACE_NETWORK, ConcernSide::Handler),
            ));
        }, action: 'network_concerns.index');
    }

    public function show(ShowConcernRequest $request): ConcernResource|JsonResponse
    {
        return $this->handle(fn (): ConcernResource => $this->present($request->concern(), ConcernResource::SPACE_NETWORK), action: 'network_concerns.show');
    }

    public function reply(ConcernMessageRequest $request, PostConcernMessage $post): JsonResponse
    {
        return $this->handle(function () use ($request, $post): JsonResponse {
            $concern = $request->concern();
            $post->execute($concern, ConcernSide::Handler, $request->body(), $request->attachments(), $request->internal(), $this->context->membership());

            return $this->present(ConcernThread::networkConcern($concern->id), ConcernResource::SPACE_NETWORK)->response()->setStatusCode(201);
        }, action: 'network_concerns.reply');
    }

    public function status(ConcernStatusRequest $request, ChangeConcernStatus $change): ConcernResource|JsonResponse
    {
        return $this->handle(fn (): ConcernResource => $this->present(
            $change->execute($request->concern(), $request->status(), $request->reason(), $this->context->membership()),
            ConcernResource::SPACE_NETWORK,
        ), action: 'network_concerns.status');
    }

    /**
     * Demandes des adhérents aux CGA, en chiffres : réseau et par CGA (F-PRE-04, RG-PRE-03). Portefeuille CGA
     * seulement : le portefeuille Cabinet n'entre dans aucune statistique du CNCGA.
     */
    public function indicators(ConcernIndicatorsRequest $request): JsonResponse
    {
        return $this->handle(function () use ($request): JsonResponse {
            $data = Tenancy::bypass(static function () use ($request): array {
                $concerns = Concern::query()
                    ->where('level', ConcernLevel::MemberToCga)
                    ->whereHas('member', static fn (Builder $member) => $member->regulatory())
                    ->when($request->organizationId(), static fn (Builder $query, string $organization) => $query->where('organization_id', $organization))
                    ->when($request->from(), static fn (Builder $query, CarbonImmutable $from) => $query->where('created_at', '>=', $from))
                    ->when($request->until(), static fn (Builder $query, CarbonImmutable $until) => $query->where('created_at', '<', $until));
                $rows = ConcernIndicators::byOrganization($concerns);
                $names = Organization::query()->whereIn('id', array_keys($rows))->pluck('name', 'id');

                return [
                    'network' => ConcernIndicators::summary($concerns),
                    'organizations' => collect($rows)
                        ->map(static fn (array $metrics, string $id): array => ['organization' => ['id' => $id, 'name' => (string) $names->get($id)], ...$metrics])
                        ->sortBy(static fn (array $row): string => mb_strtolower($row['organization']['name']))
                        ->values()
                        ->all(),
                ];
            }, 'Préoccupations : chiffres agrégés des demandes des adhérents pour le CNCGA (RG-PRE-03).');

            return response()->json(['data' => ['period' => ['from' => $request->validated('from'), 'to' => $request->validated('to')], ...$data]]);
        }, action: 'network_concerns.indicators');
    }
}
