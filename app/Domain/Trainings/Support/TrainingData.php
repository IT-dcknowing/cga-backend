<?php

namespace App\Domain\Trainings\Support;

use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Trainings\Enums\AudienceType;
use App\Domain\Trainings\Enums\TrainingFormat;
use Carbon\CarbonImmutable;

/**
 * Champs saisis d'une formation (F-FOR-01), validés par sa FormRequest.
 */
final readonly class TrainingData
{
    /**
     * @param  list<array{type: AudienceType, regime: TaxRegime|null}>  $audiences
     */
    public function __construct(
        public string $title,
        public ?string $description,
        public TrainingFormat $format,
        public ?string $location,
        public ?string $onlineUrl,
        public ?string $trainer,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public ?int $capacity,
        public int $price,
        public bool $requiresApproval,
        public array $audiences,
    ) {}
}
