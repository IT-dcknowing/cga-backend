<?php

namespace App\Domain\Referentials\ReferenceData;

use Illuminate\Database\Eloquent\Model;

/**
 * Effet d'un fichier de référence sur le jeu (aperçu avant confirmation, RG-DATA-01) : valeurs
 * créées, modifiées (champ par champ), inchangées, retirées en mode « remplacer » avec leur sort, et
 * lignes rejetées avec leurs motifs.
 */
final readonly class ImportPlan
{
    /**
     * @param  list<ParsedRow>  $creates
     * @param  list<array{row: ParsedRow, model: Model, changes: array<string, array{0: mixed, 1: mixed}>}>  $updates
     * @param  list<array{model: Model, removal: Removal}>  $removals
     * @param  list<ParsedRow>  $rejected
     * @param  list<ParsedRow>  $warned
     */
    public function __construct(
        public ReferenceDataset $dataset,
        public ImportMode $mode,
        public array $creates,
        public array $updates,
        public int $unchanged,
        public array $removals,
        public array $rejected,
        public array $warned,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(DatasetDefinition $definition): array
    {
        return [
            'dataset' => $this->dataset->value,
            'mode' => $this->mode->value,
            'summary' => [
                'creates' => count($this->creates),
                'updates' => count($this->updates),
                'unchanged' => $this->unchanged,
                'deletes' => $this->removalCount(Removal::Delete),
                'deactivates' => $this->removalCount(Removal::Deactivate),
                'kept' => $this->removalCount(Removal::Keep),
                'rejected' => count($this->rejected),
            ],
            'creates' => array_map(static fn (ParsedRow $row): array => ['row' => $row->number, 'key' => $row->key, 'label' => $row->label], $this->creates),
            'updates' => array_map(static fn (array $update): array => [
                'row' => $update['row']->number,
                'key' => $update['row']->key,
                'label' => $definition->labelOf($update['model']),
                'changes' => array_map(static fn (array $change): array => ['before' => $change[0], 'after' => $change[1]], $update['changes']),
            ], $this->updates),
            'removals' => array_map(static fn (array $removal): array => [
                'id' => (string) $removal['model']->getKey(),
                'label' => $definition->labelOf($removal['model']),
                'action' => $removal['removal']->value,
                'action_label' => $removal['removal']->label(),
            ], $this->removals),
            'rejected' => array_map(static fn (ParsedRow $row): array => ['row' => $row->number, 'label' => $row->label, 'messages' => $row->errors], $this->rejected),
            'warnings' => array_map(static fn (ParsedRow $row): array => ['row' => $row->number, 'label' => $row->label, 'messages' => $row->warnings], $this->warned),
        ];
    }

    private function removalCount(Removal $removal): int
    {
        return count(array_filter($this->removals, static fn (array $r): bool => $r['removal'] === $removal));
    }
}
