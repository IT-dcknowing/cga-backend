<?php

namespace App\Domain\Imports\Jobs;

use App\Domain\Imports\Actions\RunImport;
use App\Domain\Imports\Enums\ImportStatus;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Traitement d'un import en file « imports » (02-TRD §1, §12), dans l'espace de celui qui a déposé
 * le fichier. Un seul essai : relancé, il rejetterait comme doublons les lignes déjà importées.
 */
class ProcessImport implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'imports';

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $options  choix faits au dépôt du fichier
     */
    public function __construct(
        public readonly string $batchId,
        public readonly string $membershipId,
        public readonly array $options = [],
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(TenantContext $context, RunImport $run): void
    {
        $context->runAs($this->membership(), fn (): ImportBatch => $run->execute(ImportBatch::query()->findOrFail($this->batchId), $this->options));
    }

    /**
     * Erreur inattendue : le lot passe en échec (l'erreur est signalée par le journal des Jobs).
     */
    public function failed(?Throwable $exception): void
    {
        app(TenantContext::class)->runAs($this->membership(), function (): void {
            $batch = ImportBatch::query()->find($this->batchId);
            if ($batch !== null && $batch->status !== ImportStatus::Done) {
                $batch->status = ImportStatus::Failed;
                $batch->finished_at = now();
                $batch->save();
            }
        });
    }

    private function membership(): Membership
    {
        return Membership::query()->with('organization')->findOrFail($this->membershipId);
    }
}
