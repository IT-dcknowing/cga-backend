<?php

namespace App\Domain\Referentials\Actions;

use App\Domain\Referentials\Enums\SectorStatus;
use App\Domain\Referentials\Models\Sector;
use App\Domain\Users\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Secteur national créé par le CNCGA (F-REF-01) : validé d'emblée, au nom de son auteur. Les
 * propositions d'un CGA (RG-REF-01) viennent avec l'inscription des adhérents.
 */
class CreateSector
{
    /**
     * @param  array{code: string, name: string}  $attributes
     */
    public function execute(array $attributes, User $author): Sector
    {
        return DB::transaction(static function () use ($attributes, $author): Sector {
            $sector = new Sector($attributes);
            $sector->status = SectorStatus::Validated;
            $sector->validated_by = $author->id;
            $sector->validated_at = now();
            $sector->save();

            return $sector->refresh();
        });
    }
}
