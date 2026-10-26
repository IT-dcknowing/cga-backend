<?php

namespace App\Domain\Referentials\ReferenceData\Datasets;

use App\Domain\Referentials\Contracts\Referential;
use App\Domain\Referentials\ReferenceData\DatasetDefinition;
use App\Domain\Referentials\ReferenceData\ParsedRow;
use App\Domain\Referentials\ReferenceData\Removal;
use App\Domain\Referentials\Support\ReferentialUsage;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Jeu d'un référentiel national (DR, CDI, communes, références nationales) : une ligne crée ou met à
 * jour la valeur de même clé ; une valeur absente du fichier en mode « remplacer » est supprimée si
 * rien ne l'utilise, sinon désactivée (ou gardée si le référentiel ne se désactive pas). Les valeurs
 * créées sont provisoires (RG-DATA-02).
 */
abstract class ReferentialDataset implements DatasetDefinition
{
    /**
     * Valeurs existantes par clé, lues une fois par import.
     *
     * @var array<string, Model>|null
     */
    private ?array $index = null;

    public function __construct(protected readonly ReferentialUsage $usage) {}

    /**
     * Clé d'une valeur existante, comparable à celle d'une ligne.
     */
    abstract protected function keyOf(Model $model): string;

    /**
     * Le référentiel a une colonne active (désactivation possible).
     */
    abstract protected function deactivatable(): bool;

    abstract protected function newModel(): Model;

    public function find(ParsedRow $row): ?Model
    {
        if ($this->index === null) {
            $this->index = [];
            foreach ($this->query()->get() as $model) {
                $this->index[$this->keyOf($model)] = $model;
            }
        }

        return $row->key === null ? null : ($this->index[$row->key] ?? null);
    }

    public function conflicts(ParsedRow $row, ?Model $match): array
    {
        return [];
    }

    public function changes(Model $model, ParsedRow $row): array
    {
        $changes = [];
        foreach ($row->attributes as $field => $value) {
            $current = $model->getAttribute($field);
            if ($this->normalize($current) !== $this->normalize($value)) {
                $changes[$field] = [$this->normalize($current), $this->normalize($value)];
            }
        }

        return $changes;
    }

    public function create(ParsedRow $row): Model
    {
        $model = $this->newModel();
        $model->forceFill($row->attributes);
        $model->save();

        return $model;
    }

    public function update(Model $model, ParsedRow $row): Model
    {
        $model->forceFill($row->attributes);
        $model->save();

        return $model;
    }

    public function removal(Model $model): Removal
    {
        return match (true) {
            ! $model instanceof Referential || ! $this->usage->isUsed($model) => Removal::Delete,
            $this->deactivatable() => Removal::Deactivate,
            default => Removal::Keep,
        };
    }

    public function remove(Model $model, Removal $removal): void
    {
        match ($removal) {
            Removal::Delete => $model->delete(),
            Removal::Deactivate => $model->forceFill(['active' => false])->save(),
            Removal::Keep => null,
        };
    }

    private function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            default => $value,
        };
    }
}
