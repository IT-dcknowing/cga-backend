<?php

namespace App\Domain\Documents\Http\Controllers;

use App\Domain\Documents\Actions\LaunchCertificateCampaign;
use App\Domain\Documents\Models\AttestationBlock;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Documents\Support\CertificateCampaign;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/cga/certificate-campaign : campagne annuelle d'attestations du centre (RG-DOC-06, 07 ;
 * « Paramètres → Campagne d'attestations », 03-APPFLOW §4) — aperçu de l'année en cours (dossiers
 * éligibles, déjà pourvus, bloqués avec leur motif), puis lancement en tâche de fond (202).
 */
class CertificateCampaignController extends BaseApiController
{
    public function show(CertificateCampaign $campaign): JsonResponse
    {
        return $this->handle(function () use ($campaign): JsonResponse {
            Gate::authorize('launchCampaign', GeneratedDocument::class);
            $year = today()->year;

            return response()->json(['data' => [
                'year' => $year,
                'eligible' => $campaign->eligible($year)->count(),
                'issued' => $campaign->issued($year),
                'blocked' => $campaign->blocked()->map(static fn (AttestationBlock $block): array => [
                    'member' => ['id' => $block->member->id, 'company_name' => $block->member->company_name, 'membership_number' => $block->member->membership_number],
                    'trigger' => $block->trigger->value,
                    'trigger_label' => $block->trigger->label(),
                    'reason' => $block->reason,
                    'blocked_at' => $block->blocked_at->toIso8601ZuluString(),
                ])->values(),
            ]]);
        }, action: 'certificate_campaign.show');
    }

    public function store(TenantContext $context, LaunchCertificateCampaign $launch): JsonResponse
    {
        return $this->handle(function () use ($context, $launch): JsonResponse {
            Gate::authorize('launchCampaign', GeneratedDocument::class);

            return response()->json(['data' => ['year' => today()->year, 'queued' => $launch->execute($context->membership())]], 202);
        }, action: 'certificate_campaign.store');
    }
}
