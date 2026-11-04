<?php

namespace App\Domain\Trainings\Support;

use App\Domain\Documents\Support\DocumentFormat;
use App\Domain\Notifications\Support\Recipients;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Trainings\Models\Training;
use App\Domain\Trainings\Models\TrainingRegistration;
use App\Domain\Users\Models\Membership;
use Illuminate\Database\Eloquent\Collection;

/**
 * Notifications des formations (PRD M13) :
 * - « Nouvelle formation publiée » : au public ciblé et, pour une formation du CNCGA, aux agents qui gèrent
 *   les formations des CGA dont des personnes sont ciblées (RG-FOR-02) ;
 * - « Inscription confirmée / promotion de liste d'attente / rappel J-1 » et les autres évolutions d'une
 *   inscription (attente, validation, refus, formation annulée, attestation) : à la personne inscrite.
 */
final class TrainingNotifier
{
    public function __construct(
        private readonly Recipients $recipients,
        private readonly TrainingAudiences $audiences,
    ) {}

    /**
     * @return int personnes notifiées
     */
    public function published(Training $training): int
    {
        $targeted = $this->audiences->memberships($training);
        $values = ['title' => $training->title, 'date' => DocumentFormat::date($training->starts_at)];

        $notified = $this->recipients->notify($targeted->whereNotNull('member_id'), 'training_published', $values, '/adherent/formations');
        $agents = $targeted->whereNull('member_id')->keyBy('id');
        if ($training->organization->type === OrganizationType::Cncga) {
            foreach ($targeted->pluck('organization_id')->unique() as $organizationId) {
                $this->recipients->agents((string) $organizationId, 'trainings.cga.manage')->each(static function (Membership $agent) use ($agents): void {
                    $agents->put($agent->id, $agent);
                });
            }
        }

        return $notified + $this->recipients->notify($agents->values(), 'training_published', $values, '/cga/formations');
    }

    /**
     * @param  string  $event  clé de lang/fr/trainings.php (events)
     * @param  array<string, string|int>  $values
     */
    public function registration(TrainingRegistration $registration, string $event, array $values = []): void
    {
        TrainingAccess::acrossCentres(static fn () => $registration->loadMissing(['membership.user', 'training']));
        $this->recipients->notify(
            new Collection([$registration->membership]),
            'training_registration',
            ['title' => $registration->training->title, 'event' => (string) __("trainings.events.{$event}", $values)],
            $registration->member_id !== null ? '/adherent/formations' : '/cga/formations',
        );
    }
}
