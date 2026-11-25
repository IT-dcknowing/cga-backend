<?php

namespace App\Domain\Statistics\Actions;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Statistics\Jobs\RefreshStatisticsJob;
use App\Support\Http\Exceptions\TooManyAttemptsException;
use App\Support\RateLimiting\AttemptLimiter;

/**
 * Bouton « Actualiser » des tableaux de bord (02-TRD §9) : une demande par organisation au plus dans la fenêtre de
 * rate_limit.stats_refresh (1 fois toutes les 10 minutes par défaut), puis le recalcul en file — le CGA de l'espace,
 * ou tout le réseau pour le CNCGA.
 */
final class RequestStatisticsRefresh
{
    private const LIMIT = 'rate_limit.stats_refresh';

    public function __construct(private readonly AttemptLimiter $limiter) {}

    /**
     * @return array{scope: string, organization_id: string|null}
     *
     * @throws TooManyAttemptsException demande renouvelée trop tôt (429, délai d'attente)
     */
    public function execute(Organization $organization): array
    {
        $this->limiter->consume(self::LIMIT, $organization->id);
        $target = $organization->type === OrganizationType::Cga ? $organization->id : null;
        RefreshStatisticsJob::dispatch($target);

        return ['scope' => $target === null ? 'network' : 'organization', 'organization_id' => $target];
    }
}
