<?php

namespace App\Domain\Trainings\Http\Controllers;

use App\Domain\Trainings\Actions\CancelRegistration;
use App\Domain\Trainings\Actions\RegisterForTraining;
use App\Domain\Trainings\Http\Requests\CancelRegistrationRequest;
use App\Domain\Trainings\Http\Requests\CatalogTrainingRequest;
use App\Domain\Trainings\Http\Requests\ListTrainingsRequest;
use App\Domain\Trainings\Http\Requests\RegisterPeopleRequest;
use App\Domain\Trainings\Http\Requests\TrainingCandidatesRequest;
use App\Domain\Trainings\Http\Requests\TrainingRegistrationsRequest;
use App\Domain\Trainings\Http\Resources\TrainingRegistrationResource;
use App\Domain\Trainings\Http\Resources\TrainingResource;
use App\Domain\Trainings\Models\Training;
use App\Domain\Trainings\Models\TrainingRegistration;
use App\Domain\Trainings\Support\TrainingAccess;
use App\Domain\Trainings\Support\TrainingCandidates;
use App\Domain\Trainings\Support\TrainingCatalog;
use App\Support\Config\SettingsService;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * /api/v1/cga/network-trainings : onglet « Formations du réseau » de /cga/formations — les formations du CNCGA
 * (lues par un contournement nommé), les inscriptions des agents et adhérents du centre, l'inscription de
 * personnes ciblées (« Inscrire des agents ou des adhérents ») ; /cga/training-registrations/{registration} :
 * désinscription d'une personne du centre (RG-FOR-04).
 */
class NetworkTrainingCatalogController extends BaseApiController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TrainingAccess $access,
    ) {}

    public function index(ListTrainingsRequest $request, SettingsService $settings): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($request, $settings): AnonymousResourceCollection {
            /** @var LengthAwarePaginator<int, Training> $trainings */
            $trainings = Tenancy::bypass(static fn (): LengthAwarePaginator => QueryBuilder::for(TrainingCatalog::network(), $request->listQuery())
                ->allowedFilters(AllowedFilter::exact('status'), TrainingCatalog::periodFilter())
                ->allowedSorts(...$request->sorts())
                ->defaultSort('-starts_at')
                ->paginate($request->perPage($settings), ['*'], 'page', $request->page()), 'Formations : formations du réseau vues d\'un CGA.');

            return TrainingResource::collection($trainings);
        }, action: 'network_trainings.index');
    }

    public function show(CatalogTrainingRequest $request, string $trainingId): TrainingResource|JsonResponse
    {
        return $this->handle(fn (): TrainingResource => new TrainingResource($this->access->network($trainingId)), action: 'network_trainings.show');
    }

    /** Inscriptions des agents et adhérents du centre à cette formation. */
    public function registrations(TrainingRegistrationsRequest $request, string $trainingId): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($request, $trainingId): AnonymousResourceCollection {
            $training = $this->access->network($trainingId);
            $registrations = TrainingRegistration::query()
                ->where('training_id', $training->id)
                ->when($request->status(), static fn (Builder $query, $status) => $query->where('status', $status))
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            return TrainingRegistrationResource::collection($registrations->map(
                static fn (TrainingRegistration $registration): TrainingRegistrationResource => (new TrainingRegistrationResource($registration))->withCompany(true),
            ));
        }, action: 'network_trainings.registrations');
    }

    public function candidates(TrainingCandidatesRequest $request, string $trainingId, TrainingCandidates $candidates): JsonResponse
    {
        return $this->handle(fn (): JsonResponse => response()->json(['data' => $candidates->list($this->access->network($trainingId), $request->search())]), action: 'network_trainings.candidates');
    }

    public function register(RegisterPeopleRequest $request, string $trainingId, RegisterForTraining $register): JsonResponse
    {
        return $this->handle(function () use ($request, $trainingId, $register): JsonResponse {
            $training = $this->access->network($trainingId);
            $counts = $register->execute($training, $request->people($this->context), $this->context->membership());

            return (new TrainingResource($training))->additional(['meta' => ['registrations' => $counts]])->response()->setStatusCode(201);
        }, action: 'network_trainings.register');
    }

    public function cancel(CancelRegistrationRequest $request, TrainingRegistration $registration, CancelRegistration $cancel): TrainingRegistrationResource|JsonResponse
    {
        return $this->handle(fn (): TrainingRegistrationResource => (new TrainingRegistrationResource($cancel->execute($registration, $this->context->membership())))->withCompany(true), action: 'cga_training_registrations.cancel');
    }
}
