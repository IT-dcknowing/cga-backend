<?php

namespace App\Domain\Exports\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Exports\Data\ExportCriteria;
use App\Domain\Exports\Enums\ExportStatus;
use App\Domain\Exports\Models\Export;
use App\Domain\Exports\Notifications\ExportReadyNotification;
use App\Domain\Exports\Support\ExportFormat;
use App\Domain\Exports\Support\ExportWorkbook;
use App\Support\Files\StorageService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Production d'un export (F-EXP-01), appelée par le Job GenerateExport dans l'espace et avec les droits de la
 * personne qui exporte (RG-SEC-06) : feuilles de la liste, feuille « Informations » horodatée, fichier rangé par
 * StorageService (orgs/{organisation}/exports/{année}/…), export prêt, trace « export » dans le journal d'audit et
 * notification « Export prêt ». Un export qui n'est plus en file d'attente n'est pas refait.
 */
final class RunExport
{
    private const STORAGE_TYPE = 'exports';

    public function __construct(
        private readonly Container $container,
        private readonly ExportWorkbook $workbook,
        private readonly StorageService $storage,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    public function execute(Export $export): Export
    {
        if ($export->status !== ExportStatus::Queued) {
            return $export;
        }
        $export->status = ExportStatus::Processing;
        $export->save();

        $exporter = $this->container->make($export->type->exporter());
        $now = CarbonImmutable::now();
        $result = $this->workbook->write($exporter->sheets(ExportCriteria::of($export)), fn (int $rows): array => $this->information($export, $now, $rows));
        $name = Str::slug((string) $export->type->label()).'-'.$now->setTimezone((string) config('app.timezone'))->format((string) config('cga.exports.file_timestamp')).'.xlsx';

        return DB::transaction(function () use ($export, $result, $name): Export {
            $file = $this->storage->store($result['contents'], $name, self::STORAGE_TYPE, $export->organization_id, $export->user_id);
            $export->file_id = $file->id;
            $export->rows_count = $result['rows'];
            $export->status = ExportStatus::Done;
            $export->finished_at = CarbonImmutable::now();
            $export->save();

            $this->audit->record('export', $export, context: ['type' => $export->type->value, 'rows' => $result['rows'], 'filters' => $export->filters]);
            $export->user->notify(new ExportReadyNotification($export->organization_id, $export->membership_id, $export));

            return $export;
        });
    }

    /**
     * Feuille « Informations » : l'export horodaté et son périmètre (RG-SEC-06).
     *
     * @return array<string, string>
     */
    private function information(Export $export, CarbonImmutable $now, int $rows): array
    {
        $label = static fn (string $key): string => (string) __("exports.information.{$key}");

        return [
            $label('list') => (string) $export->type->label(),
            $label('exported_at') => (string) ExportFormat::dateTime($now),
            $label('exported_by') => $export->user->fullName(),
            $label('space') => $this->context->organization()->name,
            $label('period') => $export->period_from === null
                ? $label('all_periods')
                : (string) __('exports.information.period_range', ['from' => ExportFormat::date($export->period_from), 'to' => ExportFormat::date($export->period_to)]),
            $label('filters') => $export->filters === [] ? $label('no_filters') : (string) json_encode($export->filters, JSON_UNESCAPED_UNICODE),
            $label('rows') => (string) $rows,
        ];
    }
}
