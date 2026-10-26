<?php

namespace App\Domain\Referentials\Actions;

use App\Domain\Referentials\Models\NationalReference;
use App\Domain\Users\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Références nationales d'une année (F-REF-04), saisies par le CNCGA : l'auteur de la dernière
 * saisie est retenu.
 */
class SaveNationalReference
{
    /**
     * @param  array<string, mixed>  $attributes  données validées
     */
    public function execute(NationalReference $reference, array $attributes, User $author): NationalReference
    {
        return DB::transaction(static function () use ($reference, $attributes, $author): NationalReference {
            $reference->fill($attributes);
            $reference->updated_by = $author->id;
            $reference->save();

            return $reference->refresh();
        });
    }
}
