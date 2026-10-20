<?php

namespace App\Domain\Documents\Http\Controllers;

use App\Domain\Documents\Models\File;
use App\Http\Controllers\Controller;
use App\Support\Files\StorageService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /api/v1/files/{file}/download : téléchargement par lien temporaire signé (02-TRD §6.4). Les
 * droits sont contrôlés par la route qui délivre le lien ; ici, seule la signature est vérifiée
 * (middleware « signed ») et l'accès est journalisé (journal d'activité, étape 0.7b).
 */
class FileDownloadController extends Controller
{
    public function __invoke(File $file, StorageService $storage): StreamedResponse
    {
        return $storage->download($file);
    }
}
