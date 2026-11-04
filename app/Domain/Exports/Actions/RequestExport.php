<?php

namespace App\Domain\Exports\Actions;

use App\Domain\Exports\Enums\ExportStatus;
use App\Domain\Exports\Enums\ExportType;
use App\Domain\Exports\Jobs\GenerateExport;
use App\Domain\Exports\Models\Export;
use App\Domain\Statistics\Support\StatPeriod;
use App\Domain\Users\Models\Membership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Demande d'un export Excel (F-EXP-01, 02-TRD §1 : tout ce qui est long est asynchrone) : la ligne est créée « en
 * file d'attente » dans l'espace et pour l'accès de la personne qui exporte, puis le Job GenerateExport produit
 * le fichier après la validation de la transaction ; la personne est prévenue dans la plateforme quand il est prêt.
 */
final class RequestExport
{
    /**
     * @param  array<string, mixed>  $filters  filtres validés de la liste
     */
    public function execute(ExportType $type, ?StatPeriod $period, array $filters, Membership $requester): Export
    {
        return DB::transaction(function () use ($type, $period, $filters, $requester): Export {
            $export = new Export;
            $export->organization_id = $requester->organization_id;
            $export->membership_id = $requester->id;
            $export->user_id = $requester->user_id;
            $export->type = $type;
            $export->period_type = $period?->type;
            $export->period_from = $period?->from;
            $export->period_to = $period?->to;
            $export->filters = $filters;
            $export->status = ExportStatus::Queued;
            $export->requested_at = CarbonImmutable::now();
            $export->save();

            GenerateExport::dispatch($export->id, $requester->id)->afterCommit();

            return $export;
        });
    }
}
