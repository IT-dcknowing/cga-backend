<?php

use App\Domain\Payments\Enums\CertificationMode;
use App\Domain\Payments\Enums\InvoiceStatus;
use App\Domain\Payments\Enums\InvoiceType;
use App\Domain\Payments\Fne\DgiApiFneCertifier;
use App\Domain\Payments\Models\Invoice;
use App\Domain\Payments\Models\InvoicingProvider;
use App\Support\Http\Exceptions\ExternalServiceException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * Pilote « API FNE de la DGI » (02-TRD §10 bis.1) : appel HTTPS sur un hôte autorisé seulement, jeton de
 * l'organisation, lecture de l'acceptation et du rejet, indisponibilité levée en ExternalServiceException.
 * Format provisoire, en attente de la documentation DGI ; aucun appel réseau réel (Http::fake).
 */

beforeEach(function () {
    Http::preventStrayRequests();
    config(['integrations.fne.allowed_hosts' => ['fne.dgi.test'], 'integrations.fne.timeout_seconds' => 5]);
});

function dgiProvider(string $url = 'https://fne.dgi.test/api'): InvoicingProvider
{
    $provider = new InvoicingProvider;
    $provider->mode = CertificationMode::DgiApi;
    $provider->base_url = $url;
    $provider->credentials = 'jeton-dgi';

    return $provider;
}

function dgiInvoice(): Invoice
{
    $invoice = new Invoice;
    $invoice->type = InvoiceType::Invoice;
    $invoice->number = 'FAC-TST-2026-00001';
    $invoice->issue_date = today()->toImmutable();
    $invoice->lines = [['charge_id' => null, 'label' => 'Droit d\'adhésion', 'quantity' => 1, 'unit_amount' => 25_000, 'tax_rate' => 18.0, 'amount' => 25_000]];
    $invoice->amount_excl_tax = 25_000;
    $invoice->tax_amount = 4_500;
    $invoice->amount_incl_tax = 29_500;
    $invoice->status = InvoiceStatus::PendingCertification;
    $invoice->certification_mode = CertificationMode::DgiApi;

    return $invoice;
}

it('envoie la facture avec le jeton de l\'organisation et lit les éléments de certification', function () {
    Http::fake(['fne.dgi.test/api/invoices' => Http::response(['status' => 'certified', 'fne_number' => 'FNE-001', 'qr' => 'QR-001', 'stamp' => 'CACHET', 'certified_at' => '2026-10-25T10:00:00Z', 'reference' => 'REF-1'])]);

    $result = app(DgiApiFneCertifier::class)->certify(dgiInvoice(), dgiProvider());

    expect($result->accepted)->toBeTrue()
        ->and($result->fneNumber)->toBe('FNE-001')
        ->and($result->qrPayload)->toBe('QR-001')
        ->and($result->stamp)->toBe('CACHET')
        ->and($result->reference)->toBe('REF-1');
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer jeton-dgi')
        && $request['number'] === 'FAC-TST-2026-00001' && $request['amount_incl_tax'] === 29_500);
});

it('lit le rejet et son motif ; une réponse inattendue ou un service injoignable est une indisponibilité', function () {
    Http::fake(['fne.dgi.test/api/invoices' => Http::sequence()
        ->push(['status' => 'rejected', 'message' => 'NCC inconnu', 'reference' => 'REJ-1'], 422)
        ->push(['error' => 'panne'], 503)
        ->push(['status' => 'certified'], 200)
        ->pushFailedConnection()]);
    $certifier = app(DgiApiFneCertifier::class);

    $rejected = $certifier->certify(dgiInvoice(), dgiProvider());
    expect($rejected->accepted)->toBeFalse()->and($rejected->message)->toBe('NCC inconnu')->and($rejected->reference)->toBe('REJ-1');

    expect(fn () => $certifier->certify(dgiInvoice(), dgiProvider()))->toThrow(ExternalServiceException::class)
        ->and(fn () => $certifier->certify(dgiInvoice(), dgiProvider()))->toThrow(ExternalServiceException::class)
        ->and(fn () => $certifier->certify(dgiInvoice(), dgiProvider()))->toThrow(ExternalServiceException::class);
});

it('n\'appelle jamais un hôte hors de la liste blanche ni une adresse sans HTTPS', function () {
    Http::fake();
    $certifier = app(DgiApiFneCertifier::class);

    expect(fn () => $certifier->certify(dgiInvoice(), dgiProvider('https://pirate.example/api')))->toThrow(ExternalServiceException::class)
        ->and(fn () => $certifier->certify(dgiInvoice(), dgiProvider('http://fne.dgi.test/api')))->toThrow(ExternalServiceException::class)
        ->and($certifier->test(dgiProvider('https://pirate.example/api')))->toBeFalse();
    Http::assertNothingSent();
});

it('teste la connexion au service', function () {
    Http::fake(['fne.dgi.test/api/status' => Http::sequence()->push(['ok' => true])->push([], 500)->pushFailedConnection()]);
    $certifier = app(DgiApiFneCertifier::class);

    expect($certifier->test(dgiProvider()))->toBeTrue()
        ->and($certifier->test(dgiProvider()))->toBeFalse()
        ->and($certifier->test(dgiProvider()))->toBeFalse();
});
