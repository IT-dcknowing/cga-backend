<?php

namespace App\Domain\Announcements\Models;

use App\Domain\Announcements\Enums\AnnouncementAudience;
use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Documents\Models\File;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonInterface;
use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Annonce (PRD M12, F-COM-01, 02), cloisonnée à son auteur (CNCGA ou CGA) ; ses destinataires la lisent par un
 * contournement nommé (AnnouncementFeed). « member_filter » : portefeuille, régimes et secteurs des adhérents
 * d'une annonce d'un CGA.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $title
 * @property string $body
 * @property string|null $attachment_file_id
 * @property AnnouncementAudience $audience
 * @property array{portfolio: string, regimes: list<string>, sector_ids: list<string>}|null $member_filter
 * @property bool $send_email
 * @property int $recipients_count
 * @property CarbonInterface $published_at
 * @property string $created_by
 * @property-read Organization $organization
 * @property-read User $author
 * @property-read File|null $attachment
 * @property-read Collection<int, AnnouncementTarget> $targets
 */
class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use Auditable, BelongsToOrganization, HasFactory, HasUlids;

    /**
     * Écrit par les Actions des annonces seulement.
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
            'audience' => AnnouncementAudience::class,
            'member_filter' => 'array',
            'send_email' => 'boolean',
            'recipients_count' => 'integer',
            'published_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<File, $this>
     */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(File::class, 'attachment_file_id');
    }

    /**
     * CGA choisis d'une annonce du CNCGA « à certains CGA ».
     *
     * @return HasMany<AnnouncementTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(AnnouncementTarget::class);
    }

    /**
     * @return HasMany<AnnouncementRead, $this>
     */
    public function reads(): HasMany
    {
        return $this->hasMany(AnnouncementRead::class);
    }

    protected static function newFactory(): AnnouncementFactory
    {
        return AnnouncementFactory::new();
    }
}
