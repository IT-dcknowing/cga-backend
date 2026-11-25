<?php

namespace App\Domain\Referentials\ReferenceData;

use App\Domain\Imports\Contracts\SpreadsheetLayout;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Un jeu de données de référence (RG-DATA-01) : modèle Excel, lecture d'une ligne, rapprochement
 * avec la valeur existante, création et mise à jour, et sort d'une valeur absente du fichier en mode
 * « remplacer » (supprimée si rien ne l'utilise, sinon désactivée ou gardée, RG-DATA-05).
 */
interface DatasetDefinition extends SpreadsheetLayout
{
    /**
     * Toutes les valeurs du jeu.
     *
     * @return Builder<covariant Model>
     */
    public function query(): Builder;

    /**
     * @param  int  $number  numéro de la ligne Excel
     * @param  array<string, mixed>  $values  valeurs de la ligne par clé de colonne
     */
    public function parse(int $number, array $values): ParsedRow;

    /**
     * Valeur existante que la ligne désigne (même code, même numéro d'agrément, même nom…).
     */
    public function find(ParsedRow $row): ?Model;

    /**
     * Motifs de rejet de la ligne au regard des valeurs existantes : valeur créée incomplète, clé déjà
     * prise par une autre valeur…
     *
     * @return list<string>
     */
    public function conflicts(ParsedRow $row, ?Model $match): array;

    /**
     * Champs de la valeur que la ligne change : champ => [avant, après]. Une cellule vide ne change
     * rien.
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public function changes(Model $model, ParsedRow $row): array;

    public function create(ParsedRow $row): Model;

    public function update(Model $model, ParsedRow $row): Model;

    public function removal(Model $model): Removal;

    public function remove(Model $model, Removal $removal): void;

    public function labelOf(Model $model): string;
}
