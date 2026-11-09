<?php

namespace App\Domain\Trainings\Http\Controllers;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Trainings\Actions\CancelTraining;
use App\Domain\Trainings\Actions\CompleteTraining;
use App\Domain\Trainings\Actions\PublishTraining;
use App\Domain\Trainings\Actions\RecordAttendance;
use App\Domain\Trainings\Actions\RecordTrainingPayment;
use App\Domain\Trainings\Actions\RegisterForTraining;
use App\Domain\Trainings\Actions\ReviewRegistration;
use App\Domain\Trainings\Actions\SaveTraining;
use App\Domain\Trainings\Enums\RegistrationStatus;
use App\Domain\Trainings\Enums\TrainingStatus;
use App\Domain\Trainings\Http\Requests\AttendanceRequest;
use App\Domain\Trainings\Http\Requests\CancelTrainingRequest;
use App\Domain\Trainings\Http\Requests\ListTrainingsRequest;
use App\Domain\Trainings\Http\Requests\OrganizerTrainingRequest;
use App\Domain\Trainings\Http\Requests\RegisterPeopleRequest;
use App\Domain\Trainings\Http\Requests\RejectRegistrationRequest;
use App\Domain\Trainings\Http\Requests\SaveTrainingRequest;
use App\Domain\Trainings\Http\Requests\TrainingCandidatesRequest;
use App\Domain\Trainings\Http\Requests\TrainingPaymentRequest;
use App\Domain\Trainings\Http\Requests\TrainingRegistrationsRequest;
use App\Domain\Trainings\Http\Resources\TrainingRegistrationResource;
use App\Domain\Trainings\Http\Resources\TrainingResource;
use App\Domain\Trainings\Models\Training;
use App\Domain\Trainings\Models\TrainingRegistration;
use App\Domain\Trainings\Support\TrainingAccess;
use App\Domain\Trainings\Support\TrainingCandidates;
use App\Domain\Trainings\Support\TrainingCatalog;
use App\Domain\Trainings\Support\TrainingEvaluations;
use App\Support\Config\SettingsService;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * /api/v1/network/trainings (CNCGA, /cncga/formations) et /api/v1/cga/trainings (CGA, onglet « Mes formations »
 * de /cga/formations) : les formations de l'organisateur (F-FOR-01 à 04) — création, modification,
 * publication, annulation ; inscrits, liste d'attente, inscription de personnes ciblées, validation ou refus,
 * paiement et reçu ; présence, clôture de la session et attestations ; évaluations agrégées.
 */
class OrganizerTrainingController extends BaseApiController
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(ListTrainingsRequest $request, SettingsService $settings): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($request, $settings): AnonymousResourceCollection {
            $trainings = QueryBuilder::for(Training::query()->with(['organization', 'audiences']), $request->listQuery())
                ->allowedFilters(AllowedFilter::exact('status'), TrainingCatalog::periodFilter())
                ->allowedSorts(...$request->sorts())
                ->defaultSort('-starts_at')
                ->paginate($request->perPage($settings), ['*'], 'page', $request->page());

            return TrainingResource::collection($trainings);
        }, action: 'trainings.index');
    }

    public function store(SaveTrainingRequest $request, SaveTraining $save, PublishTraining $publish): JsonResponse
    {
        return $this->handle(function () use ($request, $save, $publish): JsonResponse {
            $training = $save->create($request->trainingData(), $this->context->membership());
            $notified = $request->publish() ? $publish->execute($training) : null;
            $this->logSubject($training);

            return $this->present($training->refresh())->additional(['meta' => ['notified' => $notified]])->response()->setStatusCode(201);
        }, action: 'trainings.store');
    }

    public function show(OrganizerTrainingRequest $request, Training $training): TrainingResource|JsonResponse
    {
        return $this->handle(fn (): TrainingResource => $this->present($training), action: 'trainings.show');
    }

    public function update(SaveTrainingRequest $request, Training $training, SaveTraining $save): TrainingResource|JsonResponse
    {
        return $this->handle(fn (): TrainingResource => $this->present($save->update($training, $request->trainingData())), action: 'trainings.update');
    }

    public function publish(OrganizerTrainingRequest $request, Training $training, PublishTraining $publish): TrainingResource|JsonResponse
    {
        return $this->handle(function () use ($training, $publish): TrainingResource {
            $notified = $publish->execute($training);

            return $this->present($training->refresh())->additional(['meta' => ['notified' => $notified]]);
        }, action: 'trainings.publish');
    }

    public function cancel(CancelTrainingRequest $request, Training $training, CancelTraining $cancel): TrainingResource|JsonResponse
    {
        return $this->handle(fn (): TrainingResource => $this->present($cancel->execute($training, $request->reason())), action: 'trainings.cancel');
    }

    /** Onglets Inscrits, Liste d'attente, Présence : toutes les inscriptions, filtre facultatif par statut. */
    public function registrations(TrainingRegistrationsRequest $request, Training $training): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($request, $training): AnonymousResourceCollection {
            $status = $request->status();
            $registrations = TrainingAccess::acrossCentres(static fn () => TrainingAccess::registrations($training)
                ->when($status, static fn (Builder $query, RegistrationStatus $status) => $query->where('status', $status))
                ->orderBy('created_at')
                ->orderBy('id')
                ->get());
            $company = $this->context->organizationType() === OrganizationType::Cga;

            return TrainingRegistrationResource::collection($registrations->map(
                static fn (TrainingRegistration $registration): TrainingRegistrationResource => (new TrainingRegistrationResource($registration))->withCompany($company),
            ));
        }, action: 'trainings.registrations');
    }

    public function candidates(TrainingCandidatesRequest $request, Training $training, TrainingCandidates $candidates): JsonResponse
    {
        return $this->handle(fn (): JsonResponse => response()->json(['data' => $candidates->list($training, $request->search())]), action: 'trainings.candidates');
    }

    public function register(RegisterPeopleRequest $request, Training $training, RegisterForTraining $register): JsonResponse
    {
        return $this->handle(function () use ($request, $training, $register): JsonResponse {
            $counts = $register->execute($training, $request->people($this->context), $this->context->membership());

            return $this->present($training->refresh())->additional(['meta' => ['registrations' => $counts]])->response()->setStatusCode(201);
        }, action: 'trainings.register');
    }

    public function approve(OrganizerTrainingRequest $request, Training $training, string $registrationId, ReviewRegistration $review): TrainingRegistrationResource|JsonResponse
    {
        return $this->handle(fn (): TrainingRegistrationResource => $this->registration($review->approve(TrainingAccess::registration($training, $registrationId))), action: 'trainings.approve');
    }

    public function reject(RejectRegistrationRequest $request, Training $training, string $registrationId, ReviewRegistration $review): TrainingRegistrationResource|JsonResponse
    {
        return $this->handle(fn (): TrainingRegistrationResource => $this->registration($review->reject(TrainingAccess::registration($training, $registrationId), $request->reason())), action: 'trainings.reject');
    }

    public function payment(TrainingPaymentRequest $request, Training $training, string $registrationId, RecordTrainingPayment $record): TrainingRegistrationResource|JsonResponse
    {
        return $this->handle(function () use ($request, $training, $registrationId, $record): TrainingRegistrationResource {
            $registration = $record->execute(TrainingAccess::registration($training, $registrationId), $request->paidOn(), $request->paymentMethod(), $request->reference(), $this->context->membership());
            $this->logSubject($registration);

            return $this->registration($registration);
        }, action: 'trainings.payment');
    }

    public function attendance(AttendanceRequest $request, Training $training, RecordAttendance $record): JsonResponse
    {
        return $this->handle(fn (): JsonResponse => response()->json(['data' => ['marked' => $record->execute($training, $request->marks())]]), action: 'trainings.attendance');
    }

    public function complete(OrganizerTrainingRequest $request, Training $training, CompleteTraining $complete): TrainingResource|JsonResponse
    {
        return $this->handle(function () use ($training, $complete): TrainingResource {
            $certificates = $complete->execute($training, $this->context->membership());

            return $this->present($training->refresh())->additional(['meta' => ['certificates' => $certificates]]);
        }, action: 'trainings.complete');
    }

    /** Onglet Évaluations : résultats agrégés, commentaires sans nom (F-FOR-04). */
    public function evaluations(OrganizerTrainingRequest $request, Training $training): JsonResponse
    {
        return $this->handle(fn (): JsonResponse => response()->json(['data' => TrainingEvaluations::summary($training)]), action: 'trainings.evaluations');
    }

    private function present(Training $training): TrainingResource
    {
        $training->loadMissing(['organization', 'audiences']);
        $open = $training->status === TrainingStatus::Published && $training->starts_at->greaterThan(CarbonImmutable::now());

        return (new TrainingResource($training))->withAbilities([
            'update' => $training->status === TrainingStatus::Draft || $open,
            'publish' => $training->status === TrainingStatus::Draft,
            'cancel' => in_array($training->status, [TrainingStatus::Draft, TrainingStatus::Published], true),
            'register' => $open,
            'attendance' => $training->status === TrainingStatus::Published && ! $open,
            'complete' => $training->status === TrainingStatus::Published && ! $open,
        ]);
    }

    private function registration(TrainingRegistration $registration): TrainingRegistrationResource
    {
        return (new TrainingRegistrationResource($registration))->withCompany($this->context->organizationType() === OrganizationType::Cga);
    }
}
