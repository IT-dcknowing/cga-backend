<?php

namespace App\Domain\Notifications\Http\Controllers;

use App\Domain\Notifications\Actions\ActivateMessagingProvider;
use App\Domain\Notifications\Actions\SaveMessagingProvider;
use App\Domain\Notifications\Actions\TestMessagingProvider;
use App\Domain\Notifications\Http\Requests\MessagingProviderRequest;
use App\Domain\Notifications\Http\Requests\TestMessagingProviderRequest;
use App\Domain\Notifications\Http\Resources\MessagingProviderResource;
use App\Domain\Notifications\Models\MessagingProvider;
use App\Support\Http\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/platform/messaging : écran « Envois » de l'administrateur (F-ADM-06, RG-NOT-05) — comptes
 * SMS, WhatsApp et e-mail avec leurs pilotes, identifiants chiffrés jamais réaffichés, interrupteur (un
 * compte actif par canal), message de test.
 */
class MessagingProviderController extends BaseApiController
{
    public function index(): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function (): AnonymousResourceCollection {
            Gate::authorize('manage', MessagingProvider::class);

            return MessagingProviderResource::collection(MessagingProvider::query()->orderBy('channel')->orderBy('label')->get())->additional(['meta' => [
                'drivers' => array_map(static fn (array $driver): array => [
                    'channels' => $driver['channels'],
                    'settings' => array_values(array_filter(array_keys($driver['settings']), static fn (string $key): bool => ! str_contains($key, '.'))),
                    'secrets' => array_keys($driver['secrets']),
                ], (array) config('messaging.drivers')),
            ]]);
        }, action: 'messaging_providers.index');
    }

    public function store(MessagingProviderRequest $request, SaveMessagingProvider $save): JsonResponse
    {
        return $this->handle(function () use ($request, $save): JsonResponse {
            $provider = $save->execute(null, $request->channel(), $request->driver(), $request->label(), $request->settings(), $request->secrets(), $this->currentUser());
            $this->logSubject($provider);

            return (new MessagingProviderResource($provider))->response()->setStatusCode(201);
        }, action: 'messaging_providers.store');
    }

    public function update(MessagingProviderRequest $request, MessagingProvider $messagingProvider, SaveMessagingProvider $save): MessagingProviderResource|JsonResponse
    {
        return $this->handle(fn (): MessagingProviderResource => new MessagingProviderResource(
            $save->execute($messagingProvider, $request->channel(), $request->driver(), $request->label(), $request->settings(), $request->secrets(), $this->currentUser()),
        ), action: 'messaging_providers.update');
    }

    public function activate(MessagingProvider $messagingProvider, ActivateMessagingProvider $activate): MessagingProviderResource|JsonResponse
    {
        return $this->handle(function () use ($messagingProvider, $activate): MessagingProviderResource {
            Gate::authorize('manage', MessagingProvider::class);

            return new MessagingProviderResource($activate->execute($messagingProvider, true, $this->currentUser()));
        }, action: 'messaging_providers.activate');
    }

    public function deactivate(MessagingProvider $messagingProvider, ActivateMessagingProvider $activate): MessagingProviderResource|JsonResponse
    {
        return $this->handle(function () use ($messagingProvider, $activate): MessagingProviderResource {
            Gate::authorize('manage', MessagingProvider::class);

            return new MessagingProviderResource($activate->execute($messagingProvider, false, $this->currentUser()));
        }, action: 'messaging_providers.deactivate');
    }

    public function test(TestMessagingProviderRequest $request, MessagingProvider $messagingProvider, TestMessagingProvider $test): JsonResponse
    {
        return $this->handle(fn (): JsonResponse => response()->json(['data' => $test->execute($messagingProvider, $request->recipient(), $request->template())]), action: 'messaging_providers.test');
    }
}
