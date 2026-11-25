<?php

namespace App\Domain\Obligations\Support;

use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * Fichier d'une version du catalogue national (F-OBL-01) : un JSON `{"version": …, "obligations": […]}`,
 * le format du référentiel attendu de DC-KNOWING (LoadObligationCatalog).
 */
final class CatalogFile
{
    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException contenu illisible
     */
    public static function decode(string $contents, string $field = 'file'): array
    {
        try {
            $catalog = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $catalog = null;
        }

        if (! is_array($catalog) || array_is_list($catalog)) {
            throw ValidationException::withMessages([$field => __('obligations.catalog_file_unreadable')]);
        }

        return $catalog;
    }
}
