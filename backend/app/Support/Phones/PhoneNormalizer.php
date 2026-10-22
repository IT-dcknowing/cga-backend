<?php

namespace App\Support\Phones;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Propaganistas\LaravelPhone\PhoneNumber;
use Propaganistas\LaravelPhone\Rules\Phone;

/**
 * Numéros de téléphone au format E.164 (+225…, RG-ACC-01) : un numéro saisi sans indicatif est lu
 * dans le pays de app.phone_country.
 */
class PhoneNormalizer
{
    public function __construct(private readonly Repository $config) {}

    /**
     * Numéro E.164, ou null si la saisie n'est pas un numéro valide.
     */
    public function e164(string $input): ?string
    {
        $number = new PhoneNumber(trim($input), $this->country());

        return $number->isValid() ? $number->formatE164() : null;
    }

    /**
     * Numéro E.164 d'une saisie déjà validée par rule().
     *
     * @throws InvalidArgumentException saisie qui n'est pas un numéro valide
     */
    public function e164OrFail(string $input): string
    {
        return $this->e164($input) ?? throw new InvalidArgumentException('Numéro de téléphone non validé.');
    }

    /**
     * Numéro enregistré présenté pour les documents : ceux du pays par défaut en paires de chiffres,
     * comme on les écrit (+225 27 22 44 55 01), les autres au format international ; une valeur qui
     * n'est pas un numéro valide reste telle quelle.
     */
    public function display(string $phone): string
    {
        $number = new PhoneNumber($phone, $this->country());
        if (! $number->isValid()) {
            return $phone;
        }
        if (! $number->isOfCountry($this->country())) {
            return $number->formatInternational();
        }

        $prefix = '+'.$number->toLibPhoneObject()->getCountryCode();

        return $prefix.' '.implode(' ', str_split(substr($number->formatE164(), strlen($prefix)), 2));
    }

    /**
     * Saisie validée dont les champs téléphone donnés passent au format E.164 ; un champ absent ou
     * vide reste tel quel.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $fields
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException numéro non validé par rule()
     */
    public function normalizeFields(array $attributes, array $fields): array
    {
        foreach ($fields as $field) {
            $value = $attributes[$field] ?? null;
            if (is_string($value) && $value !== '') {
                $attributes[$field] = $this->e164OrFail($value);
            }
        }

        return $attributes;
    }

    /**
     * Règle de validation : numéro valide, du pays par défaut ou avec son indicatif international.
     */
    public function rule(): Phone
    {
        return (new Phone)->country($this->country())->international();
    }

    public function country(): string
    {
        return $this->config->string('app.phone_country');
    }
}
