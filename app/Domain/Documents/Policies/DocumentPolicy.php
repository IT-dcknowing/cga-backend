<?php

namespace App\Domain\Documents\Policies;

use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;

/**
 * Documents officiels (M08) : consultés avec le dossier (members.view), en PDF et en Word pour les
 * agents du CGA (RG-DOC-04) ; produits, régénérés, relancés ou déposés avec documents.produce (gérant,
 * expert-comptable, comptables, secrétariat) ; annulés avec documents.cancel (gérant,
 * expert-comptable). L'adhérent lit les siens depuis son espace (member.documents.view), en PDF
 * seulement. Le cloisonnement garde chaque document dans son centre.
 */
class DocumentPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Onglet « Documents » d'un dossier.
     */
    public function viewAny(User $user, Member $member): bool
    {
        return Gate::allows('view', $member);
    }

    public function view(User $user, GeneratedDocument $document): bool
    {
        if (! $this->inCga() || $document->organization_id !== $this->context->organizationId()) {
            return false;
        }

        return $document->member === null ? $this->context->allows('members.view') : Gate::allows('view', $document->member);
    }

    /**
     * Production à la demande et dépôt d'un document dans le dossier (F-DOC-01, 05).
     */
    public function produce(User $user, Member $member): bool
    {
        return Gate::allows('view', $member) && $this->context->allows('documents.produce');
    }

    /**
     * Relance d'une production en échec (l'état du document est contrôlé par RetryDocument).
     */
    public function retry(User $user, GeneratedDocument $document): bool
    {
        return $this->view($user, $document) && $this->context->allows('documents.produce');
    }

    /**
     * Régénération ou duplicata (RG-ADH-07, RG-DOC-06 ; règles contrôlées par RegenerateDocument).
     */
    public function regenerate(User $user, GeneratedDocument $document): bool
    {
        return $this->retry($user, $document);
    }

    public function cancel(User $user, GeneratedDocument $document): bool
    {
        return $this->view($user, $document) && $this->context->allows('documents.cancel');
    }

    /**
     * Campagne annuelle d'attestations du centre (RG-DOC-06) : aperçu et lancement.
     */
    public function launchCampaign(User $user): bool
    {
        return $this->inCga() && $this->context->allows('documents.produce');
    }

    /**
     * Documents de l'adhérent dans son espace (RG-DOC-04, RG-ADH-12).
     */
    public function viewOwn(User $user): bool
    {
        return $this->context->has() && $this->context->memberId() !== null && $this->context->allows('member.documents.view');
    }

    private function inCga(): bool
    {
        return $this->context->has() && $this->context->organizationType() === OrganizationType::Cga && $this->context->memberId() === null;
    }
}
