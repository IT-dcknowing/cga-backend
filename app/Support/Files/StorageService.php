<?php

namespace App\Support\Files;

use App\Domain\Documents\Models\File;
use App\Support\Config\SettingsService;
use App\Support\Database\Constraints;
use finfo;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Routing\UrlGenerator;
<<<<<<< HEAD
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
=======
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
>>>>>>> cd06776774a9a4fdc9bf415c1ccb8b8ff0026d90
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Mime\MimeTypes;

/**
 * Seul accès aux fichiers (02-TRD §6.4) : disque Laravel de FILESYSTEM_DISK (private au démarrage,
 * S3 possible sans changer le code), arborescence orgs/{organisation}/{type}/{année}/{ulid}.{ext},
 * nom régénéré, type déterminé par le contenu, empreinte SHA-256. Téléchargement par lien
 * temporaire signé (files.download_link_minutes).
 */
class StorageService
{
    public const DOWNLOAD_ROUTE = 'files.download';

    private const NETWORK_FOLDER = 'network';

<<<<<<< HEAD
    /** Code SQLSTATE d'une clé étrangère encore utilisée (PostgreSQL). */
    private const FOREIGN_KEY_VIOLATION = '23503';

=======
>>>>>>> cd06776774a9a4fdc9bf415c1ccb8b8ff0026d90
    public function __construct(
        private readonly Repository $config,
        private readonly SettingsService $settings,
        private readonly UrlGenerator $url,
    ) {}

    /**
     * @param  string  $type  dossier du type de fichier (documents, pieces, exports…)
     */
    public function store(string $contents, string $originalName, string $type, ?string $organizationId = null, ?string $uploaderId = null): File
    {
        Constraints::identifier($type);

        $mimeType = $this->detectMimeType($contents);
        $extension = MimeTypes::getDefault()->getExtensions($mimeType)[0] ?? 'bin';
        $id = strtolower((string) Str::ulid());
        $path = sprintf('orgs/%s/%s/%s/%s.%s', $organizationId ?? self::NETWORK_FOLDER, $type, now()->format('Y'), $id, $extension);
        $disk = $this->config->string('filesystems.default');

        $this->disk($disk)->put($path, $contents);

        $file = new File;
        $file->id = $id;
        $file->organization_id = $organizationId;
        $file->disk = $disk;
        $file->path = $path;
        $file->original_name = mb_substr(basename(str_replace('\\', '/', $originalName)), 0, 255);
        $file->mime_type = $mimeType;
        $file->size_bytes = strlen($contents);
        $file->sha256 = hash('sha256', $contents);
        $file->uploaded_by = $uploaderId;
        $file->save();

        return $file;
    }

    public function storeUpload(UploadedFile $upload, string $type, ?string $organizationId = null, ?string $uploaderId = null): File
    {
        return $this->store($upload->getContent(), $upload->getClientOriginalName(), $type, $organizationId, $uploaderId);
    }

    /**
     * Type réel du contenu, jamais celui annoncé par le navigateur ni l'extension (02-TRD §6.7).
     */
    public function detectMimeType(string $contents): string
    {
        $detected = (new finfo(FILEINFO_MIME_TYPE))->buffer($contents);

        return is_string($detected) && $detected !== '' ? $detected : 'application/octet-stream';
    }

    /**
     * Lien de téléchargement signé, valable files.download_link_minutes, à donner après le contrôle des droits.
     */
    public function temporaryUrl(File $file): string
    {
        $minutes = (int) $this->settings->get('files.download_link_minutes');

        return $this->url->temporarySignedRoute(self::DOWNLOAD_ROUTE, now()->addMinutes($minutes), ['file' => $file->getKey()]);
    }

    public function contents(File $file): string
    {
        return (string) $this->disk($file->disk)->get($file->path);
    }

    public function download(File $file): StreamedResponse
    {
        return $this->disk($file->disk)->download($file->path, $file->original_name, [
            'Content-Type' => $file->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function delete(File $file): void
    {
        $this->disk($file->disk)->delete($file->path);
        $file->delete();
    }

<<<<<<< HEAD
<<<<<<< HEAD:app/Support/Files/StorageService.php
=======
    /**
     * Supprime les fichiers que plus rien ne référence (purges du PRD §7, RG-ORG-07). La ligne part d'abord, dans
     * un point de sauvegarde : si une autre donnée la référence encore (clé étrangère), le fichier est gardé et
     * signalé au journal. Le contenu n'est effacé du disque qu'après la validation de la transaction englobante,
     * pour ne jamais perdre un fichier dont la ligne serait restaurée par une annulation.
     *
     * @param  iterable<string|null>  $fileIds
     * @return int nombre de fichiers supprimés
     */
    public function deleteUnreferenced(iterable $fileIds): int
    {
        $ids = [];
        foreach ($fileIds as $id) {
            if (is_string($id) && $id !== '') {
                $ids[$id] = true;
            }
        }
        if ($ids === []) {
            return 0;
        }

        $deleted = 0;
        foreach (File::query()->whereIn('id', array_keys($ids))->get() as $file) {
            try {
                DB::transaction(static fn () => $file->delete());
            } catch (QueryException $exception) {
                if ($exception->getCode() !== self::FOREIGN_KEY_VIOLATION) {
                    throw $exception;
                }
                Log::warning('Fichier gardé à la purge : encore référencé.', ['file_id' => $file->id]);

                continue;
            }

            DB::afterCommit(fn () => $this->disk($file->disk)->delete($file->path));
            $deleted++;
        }

        return $deleted;
    }

>>>>>>> 7d183d06f03b9ec1a8df4b3b3bd6b9cce26781b9:backend/app/Support/Files/StorageService.php
=======
>>>>>>> cd06776774a9a4fdc9bf415c1ccb8b8ff0026d90
    private function disk(string $name): FilesystemAdapter
    {
        /** @var FilesystemAdapter */
        return Storage::disk($name);
    }
}
