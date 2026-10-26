<?php

namespace App\Domain\Referentials\ReferenceData;

/**
 * Ligne lue d'un fichier de référence : sa clé (code, numéro d'agrément, nom…), son libellé, les
 * valeurs à écrire (une cellule vide n'y figure pas), ses erreurs (ligne rejetée) et ses
 * avertissements (ligne gardée).
 */
final readonly class ParsedRow
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    public function __construct(
        public int $number,
        public ?string $key,
        public ?string $label,
        public array $attributes,
        public array $errors = [],
        public array $warnings = [],
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [] && $this->key !== null;
    }

    /**
     * @param  list<string>  $errors
     */
    public function rejectedWith(array $errors): self
    {
        return new self($this->number, $this->key, $this->label, $this->attributes, [...$this->errors, ...$errors], $this->warnings);
    }
}
