<?php

namespace App\Domain\Exports\Data;

/**
 * Une feuille d'un export : nom, en-têtes et lignes (valeurs simples), lues en flux.
 */
final readonly class ExportSheet
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<list<string|int|float|bool|null>>  $rows
     */
    public function __construct(
        public string $name,
        public array $headers,
        public iterable $rows,
    ) {}
}
