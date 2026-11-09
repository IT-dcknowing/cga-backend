<?php

namespace App\Domain\Concerns\Actions;

use App\Domain\Concerns\Enums\ConcernCategory;
use App\Domain\Concerns\Enums\ConcernLevel;
use App\Domain\Concerns\Enums\DocumentRequestType;
use App\Domain\Concerns\Models\Concern;
use App\Domain\Users\Models\Membership;

/**
 * « Demander un document » depuis l'espace de l'adhérent (F-DOC-06) : une préoccupation de catégorie
 * « Demande de document » à son CGA, l'objet nommant le document demandé, le message de l'adhérent (ou
 * un texte par défaut) pour premier message.
 */
class RequestDocument
{
    public function __construct(private readonly SubmitConcern $submit) {}

    public function execute(DocumentRequestType $type, ?string $message, Membership $actor): Concern
    {
        $label = $type->label();

        return $this->submit->execute(
            ConcernLevel::MemberToCga,
            ConcernCategory::DocumentRequest,
            (string) __('concerns.document_request.subject', ['type' => $label]),
            $message ?? (string) __('concerns.document_request.body', ['type' => mb_strtolower($label)]),
            [],
            $actor,
        );
    }
}
