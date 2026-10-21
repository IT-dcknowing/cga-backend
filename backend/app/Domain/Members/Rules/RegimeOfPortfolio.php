<?php

namespace App\Domain\Members\Rules;

use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Enums\TaxRegime;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Régime permis par le portefeuille (F-ADH-02, RG-ADH-08) : RME ou TEE au portefeuille CGA, les
 * quatre au portefeuille Cabinet.
 */
class RegimeOfPortfolio implements DataAwareRule, ValidationRule
{
    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    public function __construct(private readonly ?Portfolio $portfolio = null) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $portfolio = $this->portfolio ?? Portfolio::tryFrom(is_string($this->data['portfolio'] ?? null) ? $this->data['portfolio'] : '');
        $regime = is_string($value) ? TaxRegime::tryFrom($value) : null;

        if ($portfolio === Portfolio::Cga && ! in_array($regime, self::cga(), true)) {
            $fail('validation.regime_of_portfolio')->translate();
        }
    }

    /**
     * @return list<TaxRegime>
     */
    public static function cga(): array
    {
        return [TaxRegime::Rme, TaxRegime::Tee];
    }
}
