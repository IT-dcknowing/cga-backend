<?php

namespace Database\Factories;

use App\Domain\Announcements\Enums\AnnouncementAudience;
use App\Domain\Announcements\Models\Announcement;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Annonce de test : d'un CGA à tous ses adhérents du portefeuille CGA, publiée maintenant, sans e-mail. Écrite
 * hors espace actif, par un contournement nommé.
 *
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'title' => 'Fermeture exceptionnelle',
            'body' => 'Le centre sera fermé vendredi.',
            'audience' => AnnouncementAudience::OwnMembers,
            'member_filter' => ['portfolio' => 'cga', 'regimes' => [], 'sector_ids' => []],
            'send_email' => false,
            'published_at' => now(),
            'created_by' => User::factory(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        return Tenancy::bypass(fn () => parent::create($attributes, $parent), 'Fabrique de test : annonce.');
    }
}
