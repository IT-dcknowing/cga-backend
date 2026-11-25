<?php

namespace App\Domain\Members\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToOrganization;
use Database\Factories\BusinessReferrerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Apporteur d'affaires du centre (sous-portefeuille « Accessoires », RG-PTF-06) : information de gestion
 * interne, jamais exposée sans le droit members.referrers.view, ni dans un document ou une statistique.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $label
 * @property string $name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $terms
 * @property bool $active
 */
class BusinessReferrer extends Model
{
    /** @use HasFactory<BusinessReferrerFactory> */
    use Auditable, BelongsToOrganization, HasFactory, HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = ['label', 'name', 'phone', 'email', 'terms', 'active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    protected static function newFactory(): BusinessReferrerFactory
    {
        return BusinessReferrerFactory::new();
    }

    /**
     * Dossiers du portefeuille CGA rattachés à cet apporteur (« Accessoire n »).
     *
     * @return HasMany<Member, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(Member::class, 'referrer_id');
    }
}
