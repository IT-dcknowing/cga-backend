<?php

namespace App\Domain\Platform\Http\Controllers;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Platform\Actions\RecordPlatformAccess;
use App\Domain\Platform\Http\Requests\CreatePlatformAccessLogRequest;
use App\Domain\Platform\Http\Resources\PlatformAccessLogResource;
use App\Domain\Platform\Models\PlatformAccessLog;
use App\Domain\Users\Enums\Role;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Gestion et consultation des accès support platform_admin aux données d'un CGA (RG-SEC-04).
 */
class PlatformAccessLogController extends BaseApiController
{
    /**
     * Liste des accès support subis par le CGA actif (RG-SEC-04).
     */
    public function indexForCga(Request $request, TenantContext $tenant): mixed
    {
        return $this->handle(function () use ($tenant, $request): AnonymousResourceCollection {
            if (! $tenant->has() || $tenant->organizationType() !== OrganizationType::Cga) {
                abort(403, 'Accès réservé aux agents d\'un CGA.');
            }

            $orgId = $tenant->organizationId();

            $logs = PlatformAccessLog::query()
                ->with(['adminUser'])
                ->where('organization_id', $orgId)
                ->orderByDesc('started_at')
                ->paginate((int) $request->input('per_page', 25));

            return PlatformAccessLogResource::collection($logs);
        }, 'cga.support_logs.index');
    }

    /**
     * Export du journal des accès support pour le CGA actif (RG-SEC-04, CSV).
     */
    public function exportForCga(TenantContext $tenant): mixed
    {
        return $this->handle(function () use ($tenant): StreamedResponse {
            if (! $tenant->has() || $tenant->organizationType() !== OrganizationType::Cga) {
                abort(403, 'Accès réservé aux agents d\'un CGA.');
            }

            $org = $tenant->organization();
            $logs = PlatformAccessLog::query()
                ->with(['adminUser'])
                ->where('organization_id', $org->id)
                ->orderBy('started_at')
                ->get();

            $filename = sprintf('journal-acces-support-%s-%s.csv', $org->sigle ?? 'cga', now()->format('Ymd-His'));

            return response()->streamDownload(function () use ($logs): void {
                $out = fopen('php://output', 'w');
                // En-tête CSV avec BOM UTF-8 pour Excel
                fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
                fputcsv($out, ['ID', 'Date début', 'Date fin', 'Administrateur', 'Motif', 'Route / Module'], ';');

                foreach ($logs as $log) {
                    fputcsv($out, [
                        $log->id,
                        $log->started_at->format('d/m/Y H:i:s'),
                        $log->ended_at?->format('d/m/Y H:i:s') ?? 'En cours',
                        $log->adminUser?->full_name ?? 'Inconnu',
                        $log->reason,
                        $log->route ?? 'Non spécifiée',
                    ], ';');
                }

                fclose($out);
            }, $filename, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }, 'cga.support_logs.export');
    }

    /**
     * Liste globale des accès support pour l'espace plateforme.
     */
    public function indexForPlatform(Request $request, TenantContext $tenant): mixed
    {
        return $this->handle(function () use ($request, $tenant): AnonymousResourceCollection {
            if (! $tenant->has() || $tenant->role() !== Role::PlatformAdmin) {
                abort(403, 'Accès réservé à l\'administrateur de la plateforme.');
            }

            $query = PlatformAccessLog::query()->with(['adminUser', 'organization']);

            if ($orgId = $request->input('organization_id')) {
                $query->where('organization_id', $orgId);
            }

            $logs = $query->orderByDesc('started_at')->paginate((int) $request->input('per_page', 25));

            return PlatformAccessLogResource::collection($logs);
        }, 'platform.logs.index');
    }

    /**
     * Enregistre un accès support platform_admin (RG-SEC-04).
     */
    public function store(CreatePlatformAccessLogRequest $request, RecordPlatformAccess $action): mixed
    {
        return $this->handle(function () use ($request, $action): JsonResponse {
            $data = $request->validated();
            $org = Organization::findOrFail($data['organization_id']);

            $log = $action->execute(
                adminUser: $request->user(),
                organization: $org,
                reason: $data['reason'],
                route: $data['route'] ?? null,
            );

            return (new PlatformAccessLogResource($log->load(['adminUser', 'organization'])))
                ->response()
                ->setStatusCode(201);
        }, 'platform.logs.store');
    }
}

