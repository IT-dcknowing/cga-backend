<?php

namespace App\Domain\Payments\Data;

use App\Domain\Payments\Enums\PaymentMethod;
use Carbon\CarbonImmutable;

/**
 * Paiement saisi ou déclaré (F-PAY-02, 03) : montant, date, mode, référence.
 */
final readonly class PaymentEntry
{
    public function __construct(
        public int $amount,
        public CarbonImmutable $paidOn,
        public PaymentMethod $method,
        public ?string $reference,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $reference = $validated['reference'] ?? null;

        return new self(
            (int) $validated['amount'],
            CarbonImmutable::parse((string) $validated['paid_on']),
            PaymentMethod::from((string) $validated['method']),
            is_string($reference) ? $reference : null,
        );
    }
}
