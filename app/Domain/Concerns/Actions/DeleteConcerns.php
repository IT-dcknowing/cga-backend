<?php

namespace App\Domain\Concerns\Actions;

use App\Domain\Concerns\Models\Concern;
use App\Support\Files\StorageService;
use Illuminate\Support\Facades\DB;

/**
 * Supprime des préoccupations avec leurs échanges et leurs pièces jointes (PRD §7 : 5 ans ; RG-ORG-07 : CGA résilié).
 * Une préoccupation transférée (F-PRE-03) qui reste ne désigne plus celle qui part. À appeler dans une transaction,
 * hors du cloisonnement (purges de la plateforme).
 */
final class DeleteConcerns
{
    public function __construct(private readonly StorageService $storage) {}

    /**
     * @param  list<string>  $concernIds
     */
    public function execute(array $concernIds): int
    {
        if ($concernIds === []) {
            return 0;
        }

        $messageIds = DB::table('concern_messages')->whereIn('concern_id', $concernIds)->pluck('id');
        $fileIds = DB::table('concern_message_files')->whereIn('message_id', $messageIds)->pluck('file_id');

        Concern::query()->whereIn('transferred_to_concern_id', $concernIds)->update(['transferred_to_concern_id' => null]);
        Concern::query()->whereIn('transferred_from_concern_id', $concernIds)->update(['transferred_from_concern_id' => null]);

        DB::table('concern_message_files')->whereIn('message_id', $messageIds)->delete();
        $deleted = Concern::query()->whereIn('id', $concernIds)->delete();
        $this->storage->deleteUnreferenced($fileIds);

        return $deleted;
    }
}
