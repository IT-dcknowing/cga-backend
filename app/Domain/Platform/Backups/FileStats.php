<?php

namespace App\Domain\Platform\Backups;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Nombre et taille totale des fichiers d'un dossier, parcouru au fil de l'eau (sans tout charger en mémoire) :
 * relevés dans le manifeste d'une sauvegarde et contrôlés après une restauration (02-TRD §12.3).
 */
class FileStats
{
    /**
     * @return array{count: int, bytes: int}
     */
    public function measure(string $directory): array
    {
        if (! is_dir($directory)) {
            return ['count' => 0, 'bytes' => 0];
        }

        $count = 0;
        $bytes = 0;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->isFile()) {
                $count++;
                $bytes += (int) $file->getSize();
            }
        }

        return ['count' => $count, 'bytes' => $bytes];
    }
}
