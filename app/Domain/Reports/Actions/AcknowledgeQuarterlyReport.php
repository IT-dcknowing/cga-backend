<?php

namespace App\Domain\Reports\Actions;

use App\Domain\Reports\Enums\ReportStatus;
use App\Domain\Reports\Models\QuarterlyReport;
use App\Domain\Users\Models\User;
use App\Support\Files\StorageService;
use App\Support\Http\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * « Enregistrer l'accusé de réception » du SP-CGA (F-RPT-05) : date, référence et fichier scanné ; le
 * rapport transmis passe « Accusé reçu ».
 */
class AcknowledgeQuarterlyReport
{
    public function __construct(private readonly StorageService $storage) {}

    /**
     * @throws BusinessRuleException report_not_transmitted
     */
    public function execute(QuarterlyReport $report, CarbonImmutable $acknowledgedOn, string $reference, UploadedFile $scan, User $actor): QuarterlyReport
    {
        return DB::transaction(function () use ($report, $acknowledgedOn, $reference, $scan, $actor): QuarterlyReport {
            $report = QuarterlyReport::query()->lockForUpdate()->findOrFail($report->id);
            if ($report->status !== ReportStatus::Transmitted) {
                throw new BusinessRuleException('report_not_transmitted');
            }

            $report->status = ReportStatus::Acknowledged;
            $report->acknowledged_at = $acknowledgedOn;
            $report->acknowledgement_reference = $reference;
            $report->acknowledgement_file_id = $this->storage->storeUpload($scan, 'reports', $report->organization_id, $actor->id)->id;
            $report->save();

            return $report;
        });
    }
}
