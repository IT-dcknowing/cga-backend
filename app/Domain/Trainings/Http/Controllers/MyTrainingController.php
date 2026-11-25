<?php

namespace App\Domain\Trainings\Http\Controllers;

use App\Domain\Trainings\Actions\CancelRegistration;
use App\Domain\Trainings\Actions\EvaluateTraining;
use App\Domain\Trainings\Actions\RegisterForTraining;
use App\Domain\Trainings\Http\Requests\CancelRegistrationRequest;
use App\Domain\Trainings\Http\Requests\CatalogTrainingRequest;
use App\Domain\Trainings\Http\Requests\EvaluateTrainingRequest;
use App\Domain\Trainings\Http\Requests\ListTrainingsRequest;
use App\Domain\Trainings\Http\Resources\TrainingRegistrationResource;
use App\Domain\Trainings\Http\Resources\TrainingResource;
use App\Domain\Trainings\Models\Training;
use App\Domain\Trainings\Models\TrainingRegistration;
use App\Domain\Trainings\Support\TrainingAccess;
use App\Domain\Trainings\Support\TrainingAudiences;
use App\Domain\Trainings\Support\TrainingCatalog;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * /api/v1/me/trainings et /me/training-registrations : la personne de l'espace actif (/adherent/formations ; un
 * agent, pour les formations qui le ciblent) — formations ouvertes de son CGA et du réseau, « S'inscrire »,
 * « Mes inscriptions », « Se désinscrire » jusqu'à J-n (RG-FOR-04), « Évaluer » et attestation après la session.
 */
class MyTrainingController extends BaseApiController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TrainingAccess $access,
    ) {}

    public function index(ListTrainingsRequest $request, TrainingCatalog $catalog): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($catalog): AnonymousResourceCollection {
            $person = $this->context->membership();

            return TrainingResource::collection($catalog->openTo($person)->map(
                static fn (Training $training): TrainingResource => (new TrainingResource($training))->withRegistration(TrainingCatalog::registrationOf($training, $person)),
            ));
        }, action: 'my_trainings.index');
    }

    public function registrations(ListTrainingsRequest $request): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function (): AnonymousResourceCollection {
            $registrations = TrainingRegistration::query()
                ->where('membership_id', $this->context->membership()->id)
                ->latest()
                ->orderByDesc('id')
                ->get();

            return TrainingRegistrationResource::collection($registrations->map(
                static fn (TrainingRegistration $registration): TrainingRegistrationResource => (new TrainingRegistrationResource($registration))->forAttendee(),
            ));
        }, action: 'my_trainings.registrations');
    }

    /**
     * @throws NotFoundHttpException formation qui ne cible pas la personne
     */
    public function register(CatalogTrainingRequest $request, string $trainingId, RegisterForTraining $register, TrainingAudiences $audiences): JsonResponse
    {
        return $this->handle(function () use ($trainingId, $register, $audiences): JsonResponse {
            $person = $this->context->membership();
            $training = $this->access->visible($trainingId);
            if (! $audiences->targets($training, $person)) {
                throw new NotFoundHttpException;
            }
            $registration = $register->self($training, $person);
            $this->logSubject($registration);

            return (new TrainingRegistrationResource($registration))->forAttendee()->response()->setStatusCode(201);
        }, action: 'my_trainings.register');
    }

    public function cancel(CancelRegistrationRequest $request, TrainingRegistration $registration, CancelRegistration $cancel): TrainingRegistrationResource|JsonResponse
    {
        return $this->handle(fn (): TrainingRegistrationResource => (new TrainingRegistrationResource($cancel->execute($registration, $this->context->membership())))->forAttendee(), action: 'my_trainings.cancel');
    }

    public function evaluate(EvaluateTrainingRequest $request, TrainingRegistration $registration, EvaluateTraining $evaluate): TrainingRegistrationResource|JsonResponse
    {
        return $this->handle(fn (): TrainingRegistrationResource => (new TrainingRegistrationResource($evaluate->execute($registration, $request->score(), $request->comment())))->forAttendee(), action: 'my_trainings.evaluate');
    }
}
