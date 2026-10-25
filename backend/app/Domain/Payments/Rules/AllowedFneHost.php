<?php

namespace App\Domain\Payments\Rules;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Adresse du service de certification (02-TRD §10 bis, OWASP A10) : en HTTPS, sur un hôte de la liste
 * blanche integrations.fne.allowed_hosts.
 */
class AllowedFneHost implements ValidationRule
{
    public function __construct(private readonly Repository $config) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $host = is_string($value) ? parse_url($value, PHP_URL_HOST) : null;
        $https = is_string($value) && parse_url($value, PHP_URL_SCHEME) === 'https';
        $allowed = array_map('strtolower', (array) $this->config->get('integrations.fne.allowed_hosts'));

        if (! $https || ! is_string($host) || ! in_array(strtolower($host), $allowed, true)) {
            $fail('validation.fne_host')->translate();
        }
    }
}
