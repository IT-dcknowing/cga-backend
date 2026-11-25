<?php

namespace App\Domain\Auth\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Auth\Enums\OtpPurpose;
use App\Domain\Users\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Code à usage unique envoyé par SMS ou e-mail ; seule son empreinte est gardée (02-TRD §6.1).
 *
 * @property int $id
 * @property string $user_id
 * @property OtpPurpose $purpose
 * @property string $target
 * @property string $code_hash
 * @property int $attempts
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $consumed_at
 */
class OtpCode extends Model
{
    use Auditable;

    /**
     * Créé et vérifié par les Actions d'authentification seulement.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var list<string>
     */
    protected $hidden = ['code_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => OtpPurpose::class,
            'attempts' => 'integer',
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Empreinte d'un code : HMAC-SHA256 avec la clé de l'application, pour qu'un code à 6 chiffres
     * ne se retrouve pas en essayant toutes les valeurs si la table venait à fuiter.
     */
    public static function fingerprint(#[\SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
