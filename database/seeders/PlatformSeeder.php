<?php

namespace Database\Seeders;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Enums\UserStatus;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use Illuminate\Database\Seeder;

/**
 * Organisation technique « plateforme », CNCGA et premier administrateur de la plateforme
 * (06-IMPLEMENTATION-PLAN étape 0.6). Valeurs de config/cga.php (seed), provisoires et modifiables.
 * Rejouable : rien n'est créé deux fois.
 */
class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        $platform = $this->organization(OrganizationType::Platform, (array) config('cga.seed.platform'));
        $this->organization(OrganizationType::Cncga, (array) config('cga.seed.cncga'));
        $this->platformAdmin($platform);
    }

    /**
     * @param  array<string, mixed>  $seed
     */
    private function organization(OrganizationType $type, array $seed): Organization
    {
        $existing = Organization::query()->where('type', $type)->first();
        if ($existing !== null) {
            return $existing;
        }

        $organization = new Organization([
            'name' => $seed['name'],
            'sigle' => $seed['sigle'] ?? null,
            'city' => $seed['city'],
            'phone_1' => $seed['phone'],
        ]);
        $organization->type = $type;
        $organization->save();

        return $organization;
    }

    private function platformAdmin(Organization $platform): void
    {
        $seed = (array) config('cga.seed.platform_admin');
        if (blank($seed['phone'] ?? null)) {
            return;
        }

        $user = User::query()->where('phone', $seed['phone'])->first();
        if ($user === null) {
            $user = new User([
                'first_name' => $seed['first_name'],
                'last_name' => $seed['last_name'],
                'phone' => $seed['phone'],
                'email' => blank($seed['email'] ?? null) ? null : $seed['email'],
            ]);
            $user->status = UserStatus::Invited;
            $user->save();
        }

        $hasMembership = Membership::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $platform->id)
            ->where('role', Role::PlatformAdmin)
            ->exists();

        if (! $hasMembership) {
            $membership = new Membership;
            $membership->user_id = $user->id;
            $membership->organization_id = $platform->id;
            $membership->role = Role::PlatformAdmin;
            $membership->status = MembershipStatus::Invited;
            $membership->save();
        }
    }
}
