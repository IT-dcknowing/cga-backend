<?php

namespace App\Domain\Members\Actions;

use App\Domain\Members\Models\BusinessReferrer;
use App\Support\Phones\PhoneNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Fiche d'un apporteur d'affaires (RG-PTF-06) : nom, contact, libellé « Accessoire n » (proposé à la
 * suite des libellés du centre s'il n'est pas saisi), taux ou conditions en texte libre, actif ou non.
 * Un apporteur inactif n'est plus proposé aux nouveaux dossiers ; ses dossiers le gardent.
 */
class SaveReferrer
{
    public function __construct(private readonly PhoneNormalizer $phones) {}

    /**
     * @param  array<string, mixed>  $attributes  saisie validée
     */
    public function execute(?BusinessReferrer $referrer, array $attributes): BusinessReferrer
    {
        $attributes = $this->phones->normalizeFields($attributes, ['phone']);

        return DB::transaction(function () use ($referrer, $attributes): BusinessReferrer {
            $referrer ??= new BusinessReferrer(['active' => true]);
            $referrer->fill($attributes);
            if (blank($referrer->label)) {
                $referrer->label = $this->nextLabel();
            }
            $referrer->save();

            return $referrer->refresh();
        });
    }

    /**
     * « Accessoire n » suivant les libellés de ce type déjà pris dans le centre.
     */
    private function nextLabel(): string
    {
        $taken = BusinessReferrer::query()->lockForUpdate()->pluck('label')
            ->map(static fn (string $label): int => preg_match('/^Accessoire (\d+)$/u', $label, $match) === 1 ? (int) $match[1] : 0);

        return 'Accessoire '.((int) $taken->max() + 1);
    }
}
