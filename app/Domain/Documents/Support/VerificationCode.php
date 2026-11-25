<?php

namespace App\Domain\Documents\Support;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Contracts\Config\Repository;

/**
 * Code de vérification d'un document (RG-DOC-03, F-DOC-04) : 32 octets aléatoires en base64url, sans
 * aucune donnée lisible. Le QR code du document (RG-DOC-02) mène à la page publique de vérification
 * (documents.verification_url) suivie de ce code.
 */
class VerificationCode
{
    private const BYTES = 32;

    public function __construct(private readonly Repository $config) {}

    public function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::BYTES)), '+/', '-_'), '=');
    }

    public function url(string $code): string
    {
        return $this->config->string('documents.verification_url').'/'.$code;
    }

    /**
     * Image PNG du QR code qui mène à la page de vérification du document.
     */
    public function qrCode(string $code): string
    {
        $qr = (array) $this->config->get('documents.qr');

        return (new PngWriter)->write(new QrCode(data: $this->url($code), size: (int) ($qr['size'] ?? 240), margin: (int) ($qr['margin'] ?? 8)))->getString();
    }
}
