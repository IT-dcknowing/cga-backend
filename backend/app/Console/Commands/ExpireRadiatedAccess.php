<?php

namespace App\Console\Commands;

use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Models\Membership;
use Illuminate\Console\Command;

/**
 * Ferme l'accès en ligne des adhérents radiés au terme de leur consultation seule (RG-ADH-12, tâche
 * planifiée chaque jour, idempotente). Elle ne fait que restreindre des accès de dossiers déjà radiés :
 * aucune radiation n'est jamais créée ni validée ici (RG-ADH-18). Les données restent conservées.
 */
class ExpireRadiatedAccess extends Command
{
    protected $signature = 'members:radiation-expiry';

    protected $description = 'Désactive les accès en consultation seule des adhérents radiés arrivés à échéance';

    public function handle(): int
    {
        $expired = Membership::query()
            ->where('status', MembershipStatus::ReadOnly)
            ->whereNotNull('read_only_until')
            ->whereDate('read_only_until', '<', today())
            ->get();

        foreach ($expired as $access) {
            $access->status = MembershipStatus::Disabled;
            $access->disabled_at = now()->toImmutable();
            $access->save();
        }

        $this->info($expired->isEmpty() ? 'Aucun accès à fermer.' : $expired->count().' accès fermé(s).');

        return self::SUCCESS;
    }
}
