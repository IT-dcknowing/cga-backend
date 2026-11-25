<?php

namespace App\Domain\Obligations\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Version chargée du catalogue national (F-OBL-01) : nombre d'obligations, dont provisoires
 * (RG-DATA-02), en vigueur ou non, dates de chargement et de validation.
 *
 * @property array{version: string, count: int, provisional_count: int, in_force: bool, validated_at: string|null, loaded_at: string|null} $resource
 */
class CatalogVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $version = $this->resource;

        return [
            'version' => $version['version'],
            'count' => $version['count'],
            'provisional_count' => $version['provisional_count'],
            'status' => $version['provisional_count'] > 0 ? 'provisional' : 'validated',
            'in_force' => $version['in_force'],
            'loaded_at' => $version['loaded_at'],
            'validated_at' => $version['validated_at'],
        ];
    }
}
