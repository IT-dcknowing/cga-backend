<?php

namespace App\Domain\Payments\Fne;

use App\Domain\Payments\Models\Invoice;
use App\Domain\Payments\Models\InvoicingProvider;
use App\Support\Http\Exceptions\ExternalServiceException;
use Carbon\CarbonImmutable;

/**
 * Bouchon du service de certification FNE (02-TRD §10 bis.1, 13) : reproduit, selon le scénario
 * choisi, l'acceptation, le rejet et l'indisponibilité du service, sans aucun appel réseau. Il sert en
 * CI et en recette tant que la DGI n'a pas fourni son environnement de test.
 */
class FakeFneCertifier implements FneCertifier
{
    public const ACCEPT = 'accept';

    public const REJECT = 'reject';

    public const UNAVAILABLE = 'unavailable';

    /**
     * Factures soumises, dans l'ordre.
     *
     * @var list<string>
     */
    public array $submitted = [];

    /**
     * @param  list<string>  $scenarios  issue de chaque soumission, la dernière se répète
     */
    public function __construct(private array $scenarios = [self::ACCEPT]) {}

    public function certify(Invoice $invoice, InvoicingProvider $provider): FneResult
    {
        $this->submitted[] = $invoice->number;
        $scenario = count($this->scenarios) > 1 ? array_shift($this->scenarios) : ($this->scenarios[0] ?? self::ACCEPT);

        return match ($scenario) {
            self::REJECT => FneResult::rejected('NCC du client inconnu de la DGI.', 'REJ-'.$invoice->number),
            self::UNAVAILABLE => throw new ExternalServiceException('fne', 'Service FNE indisponible (bouchon).'),
            default => FneResult::accepted('FNE-'.$invoice->number, 'https://fne.example/verifier/'.$invoice->number, 'CACHET-'.$invoice->number, CarbonImmutable::now(), 'REF-'.$invoice->number),
        };
    }

    public function test(InvoicingProvider $provider): bool
    {
        return ($this->scenarios[0] ?? self::ACCEPT) !== self::UNAVAILABLE;
    }
}
