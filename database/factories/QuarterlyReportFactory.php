<?php

namespace Database\Factories;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Reports\Enums\ReportStatus;
use App\Domain\Reports\Models\QuarterlyReport;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Rapport trimestriel de test : T3 2026 en brouillon, date limite au 31 octobre et échéance
 * réglementaire au 15 novembre. Écrit hors espace actif, par un contournement nommé.
 *
 * @extends Factory<QuarterlyReport>
 */
class QuarterlyReportFactory extends Factory
{
    protected $model = QuarterlyReport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'year' => 2026,
            'quarter' => 3,
            'status' => ReportStatus::Draft,
            'deadline' => '2026-10-31',
            'legal_deadline' => '2026-11-15',
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        return Tenancy::bypass(fn () => parent::create($attributes, $parent), 'Fabrique de test : rapport trimestriel.');
    }
}
