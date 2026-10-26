<?php

namespace App\Domain\Documents\Jobs;

use App\Domain\Documents\Actions\RenderDocument;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Documents\Templates\IncompleteTemplateDataException;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use LogicException;
use Throwable;

/**
 * Production d'un document en file « documents » (02-TRD §8.2), dans l'espace de celui qui l'a demandé.
 * Essais, attentes croissantes et durée maximale : config/queue-jobs.php. Un modèle incomplet ou un
 * type sans composeur échoue sans nouvel essai ; après le dernier échec, le document passe « en échec »
 * et peut être relancé.
 */
class GenerateDocument implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout;

    public function __construct(
        public readonly string $documentId,
        public readonly string $membershipId,
    ) {
        $this->onQueue((string) config('queue-jobs.generate_document.queue'));
        $this->tries = (int) config('queue-jobs.generate_document.tries');
        $this->timeout = (int) config('queue-jobs.generate_document.timeout');
    }

    /**
     * Attente avant chaque nouvel essai (secondes).
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return array_values(array_map(intval(...), (array) config('queue-jobs.generate_document.backoff')));
    }

    public function handle(TenantContext $context, RenderDocument $render): void
    {
        $context->runAs($this->membership(), function () use ($render): void {
            $document = GeneratedDocument::query()->find($this->documentId);
            if ($document === null) {
                return;
            }

            try {
                $render->execute($document);
            } catch (IncompleteTemplateDataException|LogicException $exception) {
                $this->fail($exception);
            }
        });
    }

    /**
     * Dernier essai en échec : le document passe « en échec » (l'erreur est signalée par le journal des Jobs).
     */
    public function failed(?Throwable $exception): void
    {
        app(TenantContext::class)->runAs($this->membership(), function (): void {
            $document = GeneratedDocument::query()->find($this->documentId);
            if ($document !== null && $document->status === DocumentStatus::Generating) {
                $document->status = DocumentStatus::Failed;
                $document->save();
            }
        });
    }

    private function membership(): Membership
    {
        return Membership::query()->with('organization')->findOrFail($this->membershipId);
    }
}
