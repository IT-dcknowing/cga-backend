<?php

namespace App\Domain\Imports\Actions;

use App\Domain\Imports\Enums\ImportStatus;
use App\Domain\Imports\Enums\ImportType;
use App\Domain\Imports\Jobs\ProcessImport;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Users\Models\Membership;
use App\Support\Files\StorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Dépôt d'un fichier à importer (M17) : le fichier est rangé dans les fichiers de l'organisation, le
 * lot est mis en file d'attente et traité par le Job ProcessImport (file « imports », 02-TRD §1),
 * dans l'espace de celui qui l'a déposé.
 */
class StartImport
{
    public function __construct(private readonly StorageService $storage) {}

    /**
     * @param  array<string, mixed>  $options  choix faits au dépôt, transmis à l'importateur (numéro de départ…)
     */
    public function execute(ImportType $type, UploadedFile $upload, Membership $membership, array $options = []): ImportBatch
    {
        return DB::transaction(function () use ($type, $upload, $membership, $options): ImportBatch {
            $file = $this->storage->storeUpload($upload, 'imports', $membership->organization_id, $membership->user_id);

            $batch = new ImportBatch;
            $batch->organization_id = $membership->organization_id;
            $batch->type = $type;
            $batch->file_id = $file->id;
            $batch->status = ImportStatus::Queued;
            $batch->created_by = $membership->user_id;
            $batch->save();

            ProcessImport::dispatch($batch->id, $membership->id, $options)->afterCommit();

            return $batch->refresh();
        });
    }
}
