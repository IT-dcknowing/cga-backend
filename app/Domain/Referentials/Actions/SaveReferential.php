<?php

namespace App\Domain\Referentials\Actions;

use App\Domain\Referentials\Contracts\Referential;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Crée ou modifie une valeur d'un référentiel national (F-REF-02, F-REF-04), avec les seuls champs
 * modifiables du modèle ; la modification est inscrite au journal des modifications.
 */
class SaveReferential
{
    /**
     * @template TModel of Model&Referential
     *
     * @param  TModel  $referential
     * @param  array<string, mixed>  $attributes  données validées
     * @return TModel
     */
    public function execute(Model&Referential $referential, array $attributes): Model
    {
        return DB::transaction(static function () use ($referential, $attributes): Model {
            $referential->fill($attributes);
            $referential->save();

            // Valeurs par défaut de la base (statut des données…) relues après une création.
            return $referential->refresh();
        });
    }
}
