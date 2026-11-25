<?php

namespace App\Domain\Documents\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * Adresse IP tronquée d'une consultation de la page publique de vérification (RG-DOC-09, 02-TRD §6.6) :
 * réseau de l'adresse (documents.verification_ip_prefix : /24 en IPv4, /48 en IPv6 par défaut), jamais
 * l'adresse complète. Une valeur qui n'est pas une adresse IP n'est pas enregistrée.
 */
class IpPrefix
{
    public function __construct(private readonly Repository $config) {}

    public function of(?string $ip): ?string
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = (string) inet_pton($ip);

        $bits = (int) $this->config->get('documents.verification_ip_prefix.'.(strlen($packed) === 4 ? 'ipv4' : 'ipv6'));
        $bits = max(0, min($bits, strlen($packed) * 8));

        $masked = '';
        foreach (str_split($packed) as $index => $byte) {
            $keep = max(0, min(8, $bits - $index * 8));
            $masked .= chr(ord($byte) & (0xFF << (8 - $keep)) & 0xFF);
        }

        return inet_ntop($masked).'/'.$bits;
    }
}
