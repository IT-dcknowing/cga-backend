<?php

namespace App\Domain\Imports\Support;

use App\Domain\Members\Support\MemberNumbering;
use App\Domain\Organizations\Models\Organization;
use App\Support\Http\Exceptions\BusinessRuleException;
use App\Support\Numbering\SequenceService;
use Illuminate\Validation\ValidationException;

/**
 * Numéro de départ de la séquence CGA Connect à l'import d'un portefeuille (RG-IMP-01) : proposé à la
 * suite des numéros déjà attribués, choisi par le gérant, jamais en arrière. Les numéros demandent
 * l'abréviation du centre (RG-ORG-05).
 */
class MemberImportStart
{
    public function __construct(private readonly SequenceService $sequences) {}

    public function proposed(string $organizationId): int
    {
        return $this->sequences->current($organizationId, MemberNumbering::MEMBERSHIP) + 1;
    }

    /**
     * @return array{start: int, abbreviation: string}
     *
     * @throws BusinessRuleException abbreviation_missing
     * @throws ValidationException numéro de départ déjà attribué
     */
    public function check(string $organizationId, ?int $requested): array
    {
        $abbreviation = Organization::query()->whereKey($organizationId)->value('numbering_abbreviation');
        if (! is_string($abbreviation) || $abbreviation === '') {
            throw new BusinessRuleException('abbreviation_missing');
        }

        $proposed = $this->proposed($organizationId);
        if ($requested !== null && $requested < $proposed) {
            throw ValidationException::withMessages(['start_number' => __('imports.members.start_number_taken', ['number' => $proposed])]);
        }

        return ['start' => $requested ?? $proposed, 'abbreviation' => $abbreviation];
    }
}
