<?php

namespace App\Domain\Announcements\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CGA choisi par une annonce du CNCGA « à certains CGA » (F-COM-01). Lien de l'annonce, sans organisation propre :
 * il se lit avec elle.
 *
 * @property string $announcement_id
 * @property string $target_organization_id
 * @property-read Organization $organization
 */
class AnnouncementTarget extends Model
{
    use Auditable;

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'target_organization_id';

    protected $keyType = 'string';

    /**
     * Écrit par les Actions des annonces seulement.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'target_organization_id');
    }
}
