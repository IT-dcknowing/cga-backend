<?php

namespace App\Domain\Platform\Backups;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Manifeste d'une sauvegarde (02-TRD §12.3) : à côté de `{base}-{horodatage}.dump.gpg`, le fichier
 * `{base}-{horodatage}.manifest.json` donne le nombre de lignes de chaque table (lu dans l'instantané de pg_dump) et le
 * nombre et la taille des fichiers archivés. Il sert de référence au test de restauration.
 *
 * @phpstan-type Manifest array{
 *     version: int,
 *     created_at: string,
 *     database_file: string,
 *     tables: array<string, int>,
 *     files: array{set: string, archive: string, count_min: int, count_max: int, bytes_min: int, bytes_max: int}
 * }
 */
final class BackupManifest
{
    public const VERSION = 1;

    private const DUMP_SUFFIX = '.dump.gpg';

    private const MANIFEST_SUFFIX = '.manifest.json';

    public static function pathFor(string $databaseFile): string
    {
        return str_ends_with($databaseFile, self::DUMP_SUFFIX)
            ? substr($databaseFile, 0, -strlen(self::DUMP_SUFFIX)).self::MANIFEST_SUFFIX
            : $databaseFile.self::MANIFEST_SUFFIX;
    }

    /**
     * Manifeste le plus récent du dossier des sauvegardes de la base (les noms portent l'horodatage).
     *
     * @throws RuntimeException aucun manifeste
     */
    public static function latest(string $databaseDirectory): string
    {
        $manifests = File::glob(rtrim($databaseDirectory, '/\\').'/*'.self::MANIFEST_SUFFIX);
        if ($manifests === []) {
            throw new RuntimeException('Aucune sauvegarde avec manifeste dans '.$databaseDirectory.'.');
        }
        sort($manifests);

        return (string) end($manifests);
    }

    /**
     * @return Manifest
     *
     * @throws RuntimeException manifeste absent, illisible ou d'une autre version
     */
    public static function read(string $path): array
    {
        if (! File::exists($path)) {
            throw new RuntimeException('Manifeste introuvable : '.basename($path).'.');
        }

        $manifest = json_decode(File::get($path), true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($manifest) || ($manifest['version'] ?? null) !== self::VERSION
            || ! is_array($manifest['tables'] ?? null) || ! is_array($manifest['files'] ?? null)
            || ! is_string($manifest['database_file'] ?? null) || ! is_string($manifest['files']['set'] ?? null)
            || ! is_string($manifest['files']['archive'] ?? null)) {
            throw new RuntimeException('Manifeste invalide : '.basename($path).'.');
        }

        /** @var Manifest $manifest */
        return $manifest;
    }
}
