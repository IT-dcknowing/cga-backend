<?php

namespace App\Domain\Members\Actions;

use App\Domain\Members\Models\Member;
use App\Support\Http\Exceptions\BusinessRuleException;

/**
 * Un NCC n'a qu'un dossier ouvert dans un centre, tous portefeuilles confondus (RG-ADH-05) : on
 * bascule le dossier, on ne le duplique pas. Le dossier existant est désigné à l'agent du centre.
 */
final class AssertNccAvailable
{
    /**
     * @throws BusinessRuleException ncc_already_registered
     */
    public static function here(string $ncc, ?Member $except = null): void
    {
        $existing = Member::query()
            ->open()
            ->where('ncc', $ncc)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except?->id))
            ->first();

        if ($existing !== null) {
            throw new BusinessRuleException('ncc_already_registered', details: [
                'member_id' => $existing->id,
                'status' => $existing->status->value,
                'portfolio' => $existing->portfolio->value,
            ]);
        }
    }
}
