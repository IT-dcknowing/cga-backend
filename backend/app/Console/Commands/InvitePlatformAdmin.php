<?php

namespace App\Console\Commands;

use App\Domain\Auth\Actions\IssueInvitation;
use App\Domain\Auth\Enums\InvitationChannel;
use App\Domain\Auth\Support\ActivationLink;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lien d'activation du premier administrateur de la plateforme, créé par PlatformSeeder
 * (SEED_PLATFORM_ADMIN_PHONE) : à l'installation, la personne qui installe le lui transmet. Le lien
 * s'affiche dans la console seulement ; un nouveau lien annule le précédent.
 */
class InvitePlatformAdmin extends Command
{
    protected $signature = 'platform:invite-admin';

    protected $description = 'Émet le lien d\'activation de l\'administrateur de la plateforme encore en attente';

    public function handle(IssueInvitation $issueInvitation, ActivationLink $links): int
    {
        $membership = Membership::query()
            ->with('user')
            ->where('role', Role::PlatformAdmin)
            ->where('status', MembershipStatus::Invited)
            ->oldest()
            ->first();

        if ($membership === null) {
            $this->warn('Aucun administrateur de la plateforme en attente d\'activation (SEED_PLATFORM_ADMIN_PHONE, puis php artisan db:seed).');

            return self::FAILURE;
        }

        try {
            $token = $issueInvitation->execute($membership, InvitationChannel::Sms);
        } catch (Throwable $exception) {
            Log::error('Invitation de l\'administrateur de la plateforme : échec.', ['exception' => $exception]);

            throw $exception;
        }

        $this->info("Lien d'activation de {$membership->user->fullName()} ({$membership->user->phone}) :");
        $this->line($links->for($token));

        return self::SUCCESS;
    }
}
