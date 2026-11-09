<?php

namespace App\Domain\Platform\Http\Controllers;

use App\Domain\Platform\Actions\UpdateSetting;
use App\Domain\Platform\Http\Requests\ListSettingsRequest;
use App\Domain\Platform\Http\Requests\UpdateSettingRequest;
use App\Domain\Platform\Http\Resources\SettingResource;
use App\Domain\Platform\Models\Setting;
use App\Domain\Platform\Support\SettingsCatalog;
use App\Support\Config\SettingsService;
use App\Support\Http\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * /api/v1/network/settings (CNCGA, 03-APPFLOW §3.2 « Paramètres réseau » et « Règles du rapport ») et
 * /api/v1/platform/settings (administrateur, §6) : paramètres de l'espace par groupe, modification
 * contrôlée par le registre, retour à la valeur par défaut. Chaque modification est tracée avec l'avant
 * et l'après (audit_logs) ; un calcul conservé garde la valeur de son époque (instantané, RG-RPT-08).
 */
class SettingsController extends BaseApiController
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly SettingsCatalog $catalog,
    ) {}

    public function index(ListSettingsRequest $request): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($request): AnonymousResourceCollection {
            $space = $request->space();
            $group = $request->group();
            $keys = array_filter(
                array_keys($this->catalog->visible($space)),
                static fn (string $key): bool => $group === null || str_starts_with($key, $group.'.'),
            );

            return SettingResource::collection(array_values(array_map(fn (string $key): array => $this->describe($space, $key), $keys)))
                ->additional(['meta' => ['groups' => $this->catalog->groups($space)]]);
        }, action: 'settings.index');
    }

    public function update(UpdateSettingRequest $request, UpdateSetting $update): SettingResource|JsonResponse
    {
        return $this->handle(function () use ($request, $update): SettingResource {
            $key = $this->known($request->space(), $request->settingKey());
            $update->execute($key, $request->value(), $this->currentUser());

            return new SettingResource($this->describe($request->space(), $key));
        }, action: 'settings.update');
    }

    public function reset(Request $request, UpdateSetting $update): SettingResource|JsonResponse
    {
        return $this->handle(function () use ($request, $update): SettingResource {
            $space = $request->routeIs('platform.settings.*') ? SettingsCatalog::PLATFORM : SettingsCatalog::NETWORK;
            $key = $this->known($space, (string) $request->route('settingKey'));
            Gate::authorize('update', [Setting::class, $space, $key]);
            $update->reset($key);

            return new SettingResource($this->describe($space, $key));
        }, action: 'settings.reset');
    }

    /**
     * @throws NotFoundHttpException clé inconnue de l'espace
     */
    private function known(string $space, string $key): string
    {
        return $this->catalog->has($space, $key) ? $key : throw new NotFoundHttpException;
    }

    /**
     * @return array{key: string, type: string, levels: list<string>, value: mixed, default: mixed, overridden: bool, can_update: bool}
     */
    private function describe(string $space, string $key): array
    {
        $definition = $this->catalog->visible($space)[$key];

        return [
            'key' => $key,
            'type' => $definition['type'],
            'levels' => (array) $definition['level'],
            'value' => $this->settings->get($key),
            'default' => $definition['default'],
            'overridden' => $this->settings->overridden($key),
            'can_update' => Gate::allows('update', [Setting::class, $space, $key]),
        ];
    }
}
