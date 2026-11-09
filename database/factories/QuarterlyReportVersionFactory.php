<?php

namespace Database\Factories;

use App\Domain\Reports\Models\QuarterlyReport;
use App\Domain\Reports\Models\QuarterlyReportVersion;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Version 1 de test d'un rapport trimestriel, sans document. Écrite hors espace actif, par un
 * contournement nommé.
 *
 * @extends Factory<QuarterlyReportVersion>
 */
class QuarterlyReportVersionFactory extends Factory
{
    protected $model = QuarterlyReportVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'report_id' => QuarterlyReport::factory(),
            'organization_id' => fn (array $attributes): string => (string) Tenancy::bypass(
                static fn () => QuarterlyReport::query()->findOrFail($attributes['report_id'])->organization_id,
                'Fabrique de test : centre de la version.',
            ),
            'version' => 1,
            'snapshot' => [],
            'generated_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        return Tenancy::bypass(fn () => parent::create($attributes, $parent), 'Fabrique de test : version de rapport.');
    }
}
