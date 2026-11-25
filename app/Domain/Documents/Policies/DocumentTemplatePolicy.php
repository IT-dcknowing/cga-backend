<?php

namespace App\Domain\Documents\Policies;

use App\Domain\Documents\Models\DocumentTemplate;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Modèles de documents du réseau (F-ADM-02, RG-DATA-03) : consultés, déposés, prévisualisés et activés
 * par l'administrateur de la plateforme (platform.templates.manage), après validation du CNCGA.
 */
class DocumentTemplatePolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->manages();
    }

    public function create(User $user): bool
    {
        return $this->manages();
    }

    public function preview(User $user, DocumentTemplate $template): bool
    {
        return $this->manages();
    }

    public function activate(User $user, DocumentTemplate $template): bool
    {
        return $this->manages();
    }

    private function manages(): bool
    {
        return $this->context->has() && $this->context->allows('platform.templates.manage');
    }
}
