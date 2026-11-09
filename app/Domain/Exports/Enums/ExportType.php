<?php

namespace App\Domain\Exports\Enums;

use App\Domain\Exports\Contracts\Exporter;
use App\Domain\Exports\Exporters\ConcernsExporter;
use App\Domain\Exports\Exporters\DuesExporter;
use App\Domain\Exports\Exporters\MembersExporter;
use App\Domain\Exports\Exporters\NetworkConcernsExporter;
use App\Domain\Exports\Exporters\ObligationsExporter;
use App\Domain\Exports\Exporters\StatisticsExporter;
use App\Domain\Exports\Exporters\TrainingRegistrationsExporter;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Support\Enums\HasLabel;

/**
 * Listes exportables en Excel (F-EXP-01, RG-GEN-03), par espace, avec le droit de la liste exportée (en plus de
 * exports.run) : le périmètre d'un export est celui de la liste que l'utilisateur voit (RG-SEC-06).
 */
enum ExportType: string
{
    use HasLabel;

    case Members = 'members';
    case Concerns = 'concerns';
    case NetworkConcerns = 'network_concerns';
    case TrainingRegistrations = 'training_registrations';
    case Obligations = 'obligations';
    case Dues = 'dues';
    case Statistics = 'statistics';

    /**
     * Droit de la liste exportée dans un espace, ou null si la liste n'y existe pas.
     */
    public function permissionIn(OrganizationType $space): ?string
    {
        return match ([$this, $space]) {
            [self::Members, OrganizationType::Cga] => 'members.view',
            [self::Concerns, OrganizationType::Cga] => 'concerns.reply',
            [self::Obligations, OrganizationType::Cga] => 'obligations.manage',
            [self::TrainingRegistrations, OrganizationType::Cga] => 'trainings.cga.manage',
            [self::TrainingRegistrations, OrganizationType::Cncga] => 'trainings.network.manage',
            [self::NetworkConcerns, OrganizationType::Cncga] => 'network_concerns.view',
            [self::Dues, OrganizationType::Cncga] => 'dues.view',
            [self::Statistics, OrganizationType::Cncga] => 'statistics.national.view',
            default => null,
        };
    }

    /**
     * @return list<self>
     */
    public static function in(OrganizationType $space): array
    {
        return array_values(array_filter(self::cases(), static fn (self $type): bool => $type->permissionIn($space) !== null));
    }

    /**
     * @return class-string<Exporter>
     */
    public function exporter(): string
    {
        return match ($this) {
            self::Members => MembersExporter::class,
            self::Concerns => ConcernsExporter::class,
            self::NetworkConcerns => NetworkConcernsExporter::class,
            self::TrainingRegistrations => TrainingRegistrationsExporter::class,
            self::Obligations => ObligationsExporter::class,
            self::Dues => DuesExporter::class,
            self::Statistics => StatisticsExporter::class,
        };
    }
}
