<?php

namespace App\Domain\Payments\Fne;

use App\Domain\Payments\Models\Invoice;
use App\Domain\Payments\Models\InvoicingProvider;
use App\Support\Http\Exceptions\ExternalServiceException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pilote « API FNE de la DGI » (RG-FNE-03, 02-TRD §10 bis.1) : adresse et identifiants de
 * l'organisation (invoicing_providers), hôte en liste blanche (integrations.fne.allowed_hosts), TLS
 * vérifié, délai maximal. Le format exact des échanges sera intégré à réception de la documentation et
 * de l'environnement de test de la DGI (02-TRD §15) : la requête et la lecture de la réponse ci-dessous
 * sont provisoires et isolées ici ; le reste de la chaîne (essais, rejets, PDF certifié) n'en dépend pas.
 */
class DgiApiFneCertifier implements FneCertifier
{
    private const SERVICE = 'fne';

    public function __construct(
        private readonly Http $http,
        private readonly Repository $config,
    ) {}

    public function certify(Invoice $invoice, InvoicingProvider $provider): FneResult
    {
        $response = $this->send($provider, 'post', '/invoices', $this->payload($invoice));

        if ($response->status() === 422 || $response->json('status') === 'rejected') {
            return FneResult::rejected(Str::limit((string) ($response->json('message') ?? __('payments.fne_rejected_without_reason')), 500), $this->reference($response));
        }
        if (! $response->successful() || ! is_string($response->json('fne_number')) || ! is_string($response->json('qr'))) {
            throw new ExternalServiceException(self::SERVICE, 'Réponse inattendue du service FNE (HTTP '.$response->status().').');
        }

        return FneResult::accepted(
            (string) $response->json('fne_number'),
            (string) $response->json('qr'),
            is_string($response->json('stamp')) ? $response->json('stamp') : null,
            is_string($response->json('certified_at')) ? CarbonImmutable::parse($response->json('certified_at')) : CarbonImmutable::now(),
            $this->reference($response),
        );
    }

    public function test(InvoicingProvider $provider): bool
    {
        try {
            return $this->send($provider, 'get', '/status')->successful();
        } catch (ExternalServiceException $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $body
     *
     * @throws ExternalServiceException hôte non autorisé, service injoignable
     */
    private function send(InvoicingProvider $provider, string $method, string $path, array $body = []): Response
    {
        $baseUrl = rtrim((string) $provider->base_url, '/');
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (! is_string($host) || parse_url($baseUrl, PHP_URL_SCHEME) !== 'https' || ! in_array(strtolower($host), array_map('strtolower', (array) $this->config->get('integrations.fne.allowed_hosts')), true)) {
            throw new ExternalServiceException(self::SERVICE, 'Adresse du service FNE hors de la liste blanche.');
        }

        try {
            $request = $this->http->acceptJson()
                ->timeout($this->config->integer('integrations.fne.timeout_seconds'))
                ->withToken((string) $provider->credentials);

            return $method === 'get' ? $request->get($baseUrl.$path) : $request->post($baseUrl.$path, $body);
        } catch (ConnectionException $exception) {
            throw new ExternalServiceException(self::SERVICE, 'Service FNE injoignable.', $exception);
        } catch (Throwable $exception) {
            throw new ExternalServiceException(self::SERVICE, 'Erreur d\'appel du service FNE.', $exception);
        }
    }

    /**
     * Facture telle que la plateforme l'envoie (format provisoire, en attente de la documentation DGI).
     *
     * @return array<string, mixed>
     */
    private function payload(Invoice $invoice): array
    {
        return [
            'type' => $invoice->type->value,
            'number' => $invoice->number,
            'issue_date' => $invoice->issue_date->toDateString(),
            'original_number' => $invoice->original?->number,
            'buyer' => ['name' => $invoice->member?->company_name, 'ncc' => $invoice->member?->ncc],
            'lines' => array_map(static fn (array $line): array => [
                'label' => $line['label'],
                'quantity' => $line['quantity'],
                'unit_amount' => $line['unit_amount'],
                'tax_rate' => $line['tax_rate'],
                'amount' => $line['amount'],
            ], $invoice->lines),
            'amount_excl_tax' => $invoice->amount_excl_tax,
            'tax_amount' => $invoice->tax_amount,
            'amount_incl_tax' => $invoice->amount_incl_tax,
        ];
    }

    private function reference(Response $response): ?string
    {
        $reference = $response->json('reference');

        return is_string($reference) ? Str::limit($reference, 100, '') : null;
    }
}
