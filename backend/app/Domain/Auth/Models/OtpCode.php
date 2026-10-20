<?php

namespace App\Domain\Auth\Models;

use App\Domain\Auth\Enums\OtpPurpose;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Code à usage unique envoyé par SMS ou e-mail ; seule son empreinte est gardée (02-TRD §6.1).
 *
 * @property int $id
 * @property string $user_id
 * @property OtpPurpose $purpose
 * @property int $attempts
 */
class OtpCode extends Model
{
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
