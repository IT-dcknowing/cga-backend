<?php

namespace App\Domain\Members\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Documents\Models\File;
use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Changement de régime daté d'un dossier (F-ADH-05) : régime, date d'effet, motif, justificatif ;
 * alimente les états de surclassement du rapport.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $member_id
 * @property TaxRegime $regime
 * @property CarbonInterface $effective_date
 * @property string $reason
 * @property string|null $justification_file_id
 * @property string|null $created_by
 * @property-read Member $member
 * @property-read File|null $justification
 * @property-read User|null $author
 */
class MemberRegimeChange extends Model
{
    use Auditable, BelongsToOrganization, HasUlids;

    protected $table = 'member_regime_history';

    public const UPDATED_AT = null;

    /**
     * Écrit par les Actions du dossier seulement.
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
            'regime' => TaxRegime::class,
            'effective_date' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @return BelongsTo<File, $this>
     */
    public function justification(): BelongsTo
    {
        return $this->belongsTo(File::class, 'justification_file_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
