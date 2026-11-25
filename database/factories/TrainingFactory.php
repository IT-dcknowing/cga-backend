<?php

namespace Database\Factories;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Trainings\Enums\TrainingFormat;
use App\Domain\Trainings\Enums\TrainingStatus;
use App\Domain\Trainings\Models\Training;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Formation de test : brouillon gratuit en présentiel, sans limite de places, dans dix jours, sans public.
 * Écrite hors espace actif, par un contournement nommé.
 *
 * @extends Factory<Training>
 */
class TrainingFactory extends Factory
{
    protected $model = Training::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'title' => 'Tenue de la comptabilité SMT',
            'format' => TrainingFormat::InPerson,
            'location' => 'Abidjan, Plateau',
            'starts_at' => now()->addDays(10)->setTime(9, 0),
            'ends_at' => now()->addDays(10)->setTime(17, 0),
            'price' => 0,
            'requires_approval' => false,
            'status' => TrainingStatus::Draft,
            'created_by' => User::factory(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        return Tenancy::bypass(fn () => parent::create($attributes, $parent), 'Fabrique de test : formation.');
    }
}
