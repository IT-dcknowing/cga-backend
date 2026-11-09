<?php

namespace App\Domain\Platform\Actions;

use App\Domain\Users\Models\User;
use App\Support\Config\SettingsService;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Modification d'un paramètre du réseau ou de la plateforme (02-TRD §7.5) : valeur contrôlée par les règles
 * du registre, enregistrée avec son auteur ; la version des paramètres change, et l'avant / après est tracé
 * au journal des modifications (Setting est audité). Retour à la valeur par défaut du registre.
 */
class UpdateSetting
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @throws ValidationException valeur refusée par le registre
     * @throws BusinessRuleException setting_not_editable
     */
    public function execute(string $key, mixed $value, User $actor): void
    {
        DB::transaction(fn () => $this->settings->set($key, $value, null, $actor->id));
    }

    /**
     * @throws BusinessRuleException setting_not_editable
     */
    public function reset(string $key): void
    {
        DB::transaction(fn () => $this->settings->reset($key));
    }
}
