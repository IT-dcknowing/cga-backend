<?php

namespace App\Domain\Exports\Jobs;

use App\Domain\Exports\Actions\RunExport;
use App\Domain\Exports\Enums\ExportStatus;
use App\Domain\Exports\Models\Export;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Production d'un export en file (queue-jobs.generate_export, 02-TRD §1), dans l'espace et avec les droits de la
 * personne qui l'a demandé : le périmètre de l'export est le sien (RG-SEC-06). Une erreur est journalisée puis
 * relancée ; au dernier échec, l'export passe « en échec ».
 */
class GenerateExport implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout;

    public function __construct(
        public readonly string $exportId,
        public readonly string $membershipId,
    ) {
        $this->onQueue((string) config('queue-jobs.generate_export.queue'));
        $this->tries = (int) config('queue-jobs.generate_export.tries');
        $this->timeout = (int) config('queue-jobs.generate_export.timeout');
    }

    public function handle(TenantContext $context, RunExport $run): void
    {
        try {
            $context->runAs($this->membership(), fn (): Export => $run->execute(Export::query()->findOrFail($this->exportId)));
        } catch (Throwable $exception) {
            Log::error('Exports : échec de la production d\'un export.', ['export_id' => $this->exportId, 'exception' => $exception]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(TenantContext::class)->runAs($this->membership(), function (): void {
            $export = Export::query()->find($this->exportId);
            if ($export !== null && $export->status !== ExportStatus::Done) {
                $export->status = ExportStatus::Failed;
                $export->finished_at = now();
                $export->save();
            }
        });
    }

    private function membership(): Membership
    {
        return Membership::query()->with('organization')->findOrFail($this->membershipId);
    }
}
