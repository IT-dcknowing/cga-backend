<?php

namespace App\Domain\Payments\Fne;

use Carbon\CarbonImmutable;

/**
 * Réponse du service de certification à une facture (RG-FNE-02) : acceptée avec ses éléments de
 * certification, ou rejetée avec le motif retourné.
 */
final readonly class FneResult
{
    private function __construct(
        public bool $accepted,
        public ?string $fneNumber,
        public ?string $qrPayload,
        public ?string $stamp,
        public ?CarbonImmutable $certifiedAt,
        public ?string $reference,
        public ?string $message,
    ) {}

    public static function accepted(string $fneNumber, string $qrPayload, ?string $stamp, CarbonImmutable $certifiedAt, ?string $reference = null): self
    {
        return new self(true, $fneNumber, $qrPayload, $stamp, $certifiedAt, $reference, null);
    }

    public static function rejected(string $message, ?string $reference = null): self
    {
        return new self(false, null, null, null, null, $reference, $message);
    }
}
