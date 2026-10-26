<?php

namespace App\Domain\Referentials\Contracts;

/**
 * Valeur d'un référentiel national (05-BACKEND-SCHEMA §4) : géré par le CNCGA, lisible par tous. Une
 * valeur utilisée par d'autres données ne se supprime pas (RG-DATA-05) : elle se désactive ou se
 * fusionne.
 */
interface Referential
{
    /**
     * Tables et colonnes qui désignent une valeur de ce référentiel ; chaque étape qui en ajoute une
     * (adhérents, obligations…) la déclare ici.
     *
     * @return array<string, string> table => colonne
     */
    public static function referencedBy(): array;
}
