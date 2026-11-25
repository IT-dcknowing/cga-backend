<?php

namespace App\Domain\Documents\Http\Controllers;

use App\Domain\Documents\Models\File;
use App\Support\Files\StorageService;
use App\Support\Http\BaseApiController;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /api/v1/files/{file}/download : téléchargement par lien temporaire signé (02-TRD §6.4). Les
 * droits sont contrôlés par la route qui délivre le lien ; ici, seule la signature est vérifiée
 * (middleware « signed ») et l'accès est journalisé (documents.download).
 */
class FileDownloadController extends BaseApiController
{
    public function __invoke(File $file, StorageService $storage): StreamedResponse|JsonResponse
    {
        return $this->handle(fn (): StreamedResponse => $storage->download($file), action: 'documents.download');
    }
}
