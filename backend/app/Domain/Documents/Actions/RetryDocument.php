<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Jobs\GenerateDocument;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Users\Models\Membership;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Relance de la production d'un document en échec (02-TRD §8.2) : même ligne, donc même numéro et
 * même code de vérification, et nouveau passage du Job GenerateDocument une fois la transaction
 * validée.
 */
class RetryDocument
{
    /**
     * @throws BusinessRuleException document_not_failed
     */
    public function execute(GeneratedDocument $document, Membership $requester): GeneratedDocument
    {
        return DB::transaction(function () use ($document, $requester): GeneratedDocument {
            $document = GeneratedDocument::query()->lockForUpdate()->findOrFail($document->id);
            if ($document->status !== DocumentStatus::Failed) {
                throw new BusinessRuleException('document_not_failed');
            }

            $document->status = DocumentStatus::Generating;
            $document->save();

            GenerateDocument::dispatch($document->id, $requester->id)->afterCommit();

            return $document;
        });
    }
}
