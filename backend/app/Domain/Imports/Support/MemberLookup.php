<?php

namespace App\Domain\Imports\Support;

use App\Domain\Members\Models\Member;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dossier désigné par une ligne d'import d'historique (F-IMP-03) : n° CGA Connect (adhésion ou client),
 * ancien n° repris, ou NCC, cherché dans le centre de l'espace actif (scope d'organisation).
 */
final class MemberLookup
{
    /**
     * Le dossier ; `null` s'il est inconnu ; `false` si la désignation vise plusieurs dossiers.
     */
    public static function find(string $designation): Member|false|null
    {
        $value = trim($designation);
        $matches = Member::query()
            ->where(static fn (Builder $query) => $query
                ->where('membership_number', $value)
                ->orWhere('client_number', $value)
                ->orWhere('legacy_number', $value)
                ->orWhere('ncc', mb_strtoupper($value)))
            ->limit(2)
            ->get();

        return match ($matches->count()) {
            0 => null,
            1 => $matches->first(),
            default => false,
        };
    }
}
