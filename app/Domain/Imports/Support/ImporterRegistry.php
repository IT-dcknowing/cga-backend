<?php

namespace App\Domain\Imports\Support;

use App\Domain\Imports\Contracts\RowImporter;
use App\Domain\Imports\Enums\ImportType;
use App\Domain\Imports\Importers\CgaRowImporter;
use App\Domain\Imports\Importers\MemberRowImporter;
use App\Domain\Imports\Importers\RevenueRowImporter;
use App\Domain\Imports\Importers\TaxPaymentRowImporter;
use Illuminate\Contracts\Container\Container;

/**
 * Importateur de chaque type de fichier : registre des CGA (F-IMP-01), portefeuille d'adhérents
 * (F-IMP-02), historique des chiffres d'affaires et des paiements d'impôts (F-IMP-03).
 */
class ImporterRegistry
{
    public function __construct(private readonly Container $container) {}

    public function for(ImportType $type): RowImporter
    {
        return match ($type) {
            ImportType::Cgas => $this->container->make(CgaRowImporter::class),
            ImportType::Members => $this->container->make(MemberRowImporter::class),
            ImportType::Revenues => $this->container->make(RevenueRowImporter::class),
            ImportType::TaxPayments => $this->container->make(TaxPaymentRowImporter::class),
        };
    }
}
