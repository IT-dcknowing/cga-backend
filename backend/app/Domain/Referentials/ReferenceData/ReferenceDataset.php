<?php

namespace App\Domain\Referentials\ReferenceData;

use App\Domain\Referentials\ReferenceData\Datasets\CgaDataset;
use App\Domain\Referentials\ReferenceData\Datasets\CommuneDataset;
use App\Domain\Referentials\ReferenceData\Datasets\NationalReferenceDataset;
use App\Domain\Referentials\ReferenceData\Datasets\RegionalDirectorateDataset;
use App\Domain\Referentials\ReferenceData\Datasets\TaxOfficeDataset;
use App\Support\Enums\HasLabel;

/**
 * Jeu de données de référence remplaçable par fichier (RG-DATA-01) : liste des CGA, DR, CDI,
 * communes, références nationales. Le catalogue des obligations, les modèles de documents et les
 * codes d'activité suivent leurs étapes (Lots 3 et 4).
 */
enum ReferenceDataset: string
{
    use HasLabel;

    case Cgas = 'cgas';
    case RegionalDirectorates = 'regional-directorates';
    case TaxOffices = 'tax-offices';
    case Communes = 'communes';
    case NationalReferences = 'national-references';

    /**
     * @return class-string<DatasetDefinition>
     */
    public function definition(): string
    {
        return match ($this) {
            self::Cgas => CgaDataset::class,
            self::RegionalDirectorates => RegionalDirectorateDataset::class,
            self::TaxOffices => TaxOfficeDataset::class,
            self::Communes => CommuneDataset::class,
            self::NationalReferences => NationalReferenceDataset::class,
        };
    }

    /**
     * Droit qui permet de remplacer ou de valider le jeu : le registre pour la liste des CGA, les
     * référentiels pour les autres.
     */
    public function permission(): string
    {
        return $this === self::Cgas ? 'organizations.manage' : 'referentials.manage';
    }
}
