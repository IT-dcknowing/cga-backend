<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Users\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Mon profil (F-AUTH-05) : nom, prénom, e-mail. Un e-mail modifié n'est plus tenu pour vérifié.
 */
class UpdateProfile
{
    /**
     * @param  array{first_name: string, last_name: string, email: string|null}  $profile
     */
    public function execute(User $user, array $profile): User
    {
        return DB::transaction(function () use ($user, $profile): User {
            $user->fill($profile);
            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }
            $user->save();

            return $user;
        });
    }
}
