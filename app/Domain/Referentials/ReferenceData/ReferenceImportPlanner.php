<?php

namespace App\Domain\Referentials\ReferenceData;

use App\Domain\Imports\Support\ImportWorkbook;
use App\Domain\Imports\Support\UnreadableImportFile;
use Illuminate\Contracts\Container\Container;

/**
 * Effet d'un fichier sur un jeu de référence (RG-DATA-01), sans rien écrire : chaque ligne valide
 * crée ou met à jour la valeur qu'elle désigne ; une clé en double dans le fichier ou en conflit
 * avec une autre valeur est rejetée ; en mode « remplacer », les valeurs que le fichier ne désigne
 * pas sont retirées selon leur usage (RG-DATA-05).
 */
class ReferenceImportPlanner
{
    public function __construct(
        private readonly ImportWorkbook $workbook,
        private readonly Container $container,
    ) {}

    public function definition(ReferenceDataset $dataset): DatasetDefinition
    {
        return $this->container->make($dataset->definition());
    }

    /**
     * @throws UnreadableImportFile fichier illisible ou sans les colonnes obligatoires
     */
    public function plan(ReferenceDataset $dataset, DatasetDefinition $definition, string $path, ImportMode $mode): ImportPlan
    {
        $positions = $this->workbook->columns($path, $definition);
        $creates = $updates = $rejected = $warned = [];
        $unchanged = 0;
        $seenKeys = $matched = [];

        foreach ($this->workbook->rows($path, $positions) as $number => $values) {
            $row = $definition->parse($number, $values);
            if ($row->isValid() && isset($seenKeys[$row->key])) {
                $row = $row->rejectedWith([__('reference_data.duplicate_in_file', ['row' => $seenKeys[$row->key]])]);
            }
            $match = $row->isValid() ? $definition->find($row) : null;
            if ($row->isValid()) {
                $row = $row->rejectedWith([
                    ...$definition->conflicts($row, $match),
                    ...($match !== null && isset($matched[$match->getKey()]) ? [__('reference_data.duplicate_in_file', ['row' => $matched[$match->getKey()]])] : []),
                ]);
            }
            if (! $row->isValid()) {
                $rejected[] = $row;

                continue;
            }

            $seenKeys[(string) $row->key] = $number;
            if ($row->warnings !== []) {
                $warned[] = $row;
            }
            if ($match === null) {
                $creates[] = $row;

                continue;
            }

            $matched[$match->getKey()] = $number;
            $changes = $definition->changes($match, $row);
            if ($changes === []) {
                $unchanged++;
            } else {
                $updates[] = ['row' => $row, 'model' => $match, 'changes' => $changes];
            }
        }

        $removals = [];
        if ($mode === ImportMode::Replace) {
            foreach ($definition->query()->get() as $model) {
                if (! isset($matched[$model->getKey()])) {
                    $removals[] = ['model' => $model, 'removal' => $definition->removal($model)];
                }
            }
        }

        return new ImportPlan($dataset, $mode, $creates, $updates, $unchanged, $removals, $rejected, $warned);
    }
}
