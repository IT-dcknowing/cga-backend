<?php

namespace App\Domain\Concerns\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Concerns\Enums\ConcernSide;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Message du fil d'une préoccupation (F-PRE-02) : réponse, note interne (invisible du demandeur),
 * message système d'un changement de statut, ou copie d'un message de la demande d'origine (transfert
 * au CNCGA, F-PRE-03 : « copied_side » dit qui l'avait écrit, sans le nommer). Jamais modifié.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $concern_id
 * @property string $author_user_id
 * @property ConcernSide $author_side
 * @property string $body
 * @property bool $is_internal
 * @property bool $is_system
 * @property string|null $copied_from_message_id
 * @property ConcernSide|null $copied_side
 * @property CarbonInterface $created_at
 * @property-read User $author
 * @property-read Collection<int, ConcernMessageFile> $attachments
 */
class ConcernMessage extends Model
{
    use Auditable, BelongsToOrganization, HasUlids;

    public const UPDATED_AT = null;

    /**
     * Écrit par les Actions des préoccupations seulement.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'author_side' => ConcernSide::class,
            'copied_side' => ConcernSide::class,
            'is_internal' => 'boolean',
            'is_system' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /**
     * @return HasMany<ConcernMessageFile, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(ConcernMessageFile::class, 'message_id');
    }
}
