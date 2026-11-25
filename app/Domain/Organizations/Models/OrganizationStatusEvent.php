<?php

namespace App\Domain\Organizations\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organizations\Enums\OrganizationStatusEvent as Event;
use App\Domain\Users\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entrée de l'historique d'un CGA (F-ORG-05 à 07) : suspension, réactivation, retrait d'agrément,
 * changement d'abonnement, résiliation, avec motif, auteur et date. Écrite par les Actions seulement.
 *
 * @property int $id
 * @property string $organization_id
 * @property Event $event
 * @property string $reason
 * @property string|null $actor_id
 * @property CarbonInterface $occurred_at
 * @property-read User|null $actor
 */
class OrganizationStatusEvent extends Model
{
    use Auditable;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => Event::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
