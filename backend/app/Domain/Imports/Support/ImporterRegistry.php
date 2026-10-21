<?php

namespace App\Domain\Imports\Support;

use App\Domain\Imports\Contracts\RowImporter;
use App\Domain\Imports\Enums\ImportType;
use App\Domain\Imports\Importers\CgaRowImporter;
use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * Importateur de chaque type de fichier. Le portefeuille d'adhérents (F-IMP-02) et l'historique de
 * CA et de paiements (F-IMP-03) arrivent aux étapes 2.6 et 5.6.
 */
class ImporterRegistry
{
    public function __construct(private readonly Container $container) {}

    /**
     * @throws LogicException type pas encore importable
     */
    public function for(ImportType $type): RowImporter
    {
        return match ($type) {
            ImportType::Cgas => $this->container->make(CgaRowImporter::class),
            default => throw new LogicException("Import « {$type->value} » pas encore disponible."),
        };
    }
}
