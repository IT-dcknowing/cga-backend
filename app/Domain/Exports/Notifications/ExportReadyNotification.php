<?php

namespace App\Domain\Exports\Notifications;

use App\Domain\Exports\Models\Export;
use App\Domain\Notifications\PlatformNotification;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Models\Organization;

/**
 * « Export prêt » (03-APPFLOW §7 : opération longue, notification dans la cloche si l'utilisateur a quitté la page) :
 * à la seule personne qui a demandé l'export, dans son espace, avec le lien de l'export.
 */
final class ExportReadyNotification extends PlatformNotification
{
    private string $list;

    private string $path;

    public function __construct(string $organizationId, string $membershipId, Export $export)
    {
        parent::__construct($organizationId, $membershipId);
        $this->list = (string) $export->type->label();
        $space = Organization::query()->whereKey($organizationId)->value('type') === OrganizationType::Cncga->value ? 'cncga' : 'cga';
        $this->path = "/{$space}/exports/{$export->id}";
    }

    public function type(): string
    {
        return 'export_ready';
    }

    public function replacements(): array
    {
        return ['list' => $this->list];
    }

    public function link(): string
    {
        return $this->path;
    }
}
