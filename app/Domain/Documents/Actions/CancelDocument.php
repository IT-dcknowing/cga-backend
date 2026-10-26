<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Users\Models\User;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Annulation d'un document émis (F-DOC-03, RG-DOC-03) : motif obligatoire, date et auteur tracés. Le
 * document n'est jamais supprimé : il reste consultable, marqué annulé, et la page de vérification le
 * dit (F-DOC-04).
 */
class CancelDocument
{
    /**
     * @throws BusinessRuleException document_not_valid
     */
    public function execute(GeneratedDocument $document, string $reason, User $actor): GeneratedDocument
    {
        return DB::transaction(function () use ($document, $reason, $actor): GeneratedDocument {
            $document = GeneratedDocument::query()->lockForUpdate()->findOrFail($document->id);
            if ($document->status !== DocumentStatus::Valid) {
                throw new BusinessRuleException('document_not_valid');
            }

            $document->status = DocumentStatus::Cancelled;
            $document->cancelled_at = now()->toImmutable();
            $document->cancelled_by = $actor->id;
            $document->cancel_reason = $reason;
            $document->save();

            return $document;
        });
    }
}
