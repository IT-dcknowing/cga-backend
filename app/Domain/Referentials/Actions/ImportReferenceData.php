<?php

namespace App\Domain\Referentials\Actions;

use App\Domain\Documents\Models\File;
use App\Domain\Imports\Support\UnreadableImportFile;
use App\Domain\Referentials\ReferenceData\ImportMode;
use App\Domain\Referentials\ReferenceData\ImportPlan;
use App\Domain\Referentials\ReferenceData\ReferenceDataset;
use App\Domain\Referentials\ReferenceData\ReferenceImportPlanner;
use App\Domain\Users\Models\User;
use App\Support\Files\StorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Remplacement ou mise à jour d'un jeu de référence par fichier Excel (RG-DATA-01) : l'aperçu range
 * le fichier et montre son effet sans rien écrire ; la confirmation relit ce fichier et applique cet
 * effet en une transaction, lignes rejetées laissées de côté. Une valeur utilisée n'est jamais
 * supprimée (RG-DATA-05) ; chaque écriture est journalisée.
 */
class ImportReferenceData
{
    public const FILE_TYPE = 'reference_data';

    public function __construct(
        private readonly ReferenceImportPlanner $planner,
        private readonly StorageService $storage,
    ) {}

    /**
     * @return array{0: File, 1: array<string, mixed>} fichier rangé et aperçu
     *
     * @throws ValidationException fichier illisible
     */
    public function preview(ReferenceDataset $dataset, UploadedFile $upload, ImportMode $mode, string $organizationId, User $user): array
    {
        $file = $this->storage->storeUpload($upload, self::FILE_TYPE, $organizationId, $user->id);
        $definition = $this->planner->definition($dataset);

        return [$file, $this->withFile($file, fn (string $path): ImportPlan => $this->planner->plan($dataset, $definition, $path, $mode))->toArray($definition)];
    }

    /**
     * @return array<string, mixed> effet appliqué
     *
     * @throws ValidationException fichier illisible
     */
    public function apply(ReferenceDataset $dataset, File $file, ImportMode $mode): array
    {
        $definition = $this->planner->definition($dataset);

        return DB::transaction(function () use ($dataset, $definition, $file, $mode): array {
            $plan = $this->withFile($file, fn (string $path): ImportPlan => $this->planner->plan($dataset, $definition, $path, $mode));

            foreach ($plan->creates as $row) {
                $definition->create($row);
            }
            foreach ($plan->updates as $update) {
                $definition->update($update['model'], $update['row']);
            }
            foreach ($plan->removals as $removal) {
                $definition->remove($removal['model'], $removal['removal']);
            }

            return $plan->toArray($definition);
        });
    }

    /**
     * @template T
     *
     * @param  callable(string): T  $callback
     * @return T
     *
     * @throws ValidationException fichier illisible
     */
    private function withFile(File $file, callable $callback): mixed
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cga-reference-');
        file_put_contents($path, $this->storage->contents($file));

        try {
            return $callback($path);
        } catch (UnreadableImportFile $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        } finally {
            gc_collect_cycles();
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
