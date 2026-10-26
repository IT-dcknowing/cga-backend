<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Users\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Changement du mot de passe depuis le profil (F-AUTH-05) ; le mot de passe actuel est contrôlé par
 * la validation de la demande.
 */
class ChangePassword
{
    public function execute(User $user, #[\SensitiveParameter] string $password): void
    {
        DB::transaction(static function () use ($user, $password): void {
            $user->password = $password;
            $user->save();
        });
    }
}
