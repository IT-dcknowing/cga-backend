<?php

namespace App\Domain\Payments\Fne;

use App\Domain\Payments\Enums\CertificationMode;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;

/**
 * Pilote de certification d'un mode (integrations.fne.drivers) : aucun en mode manuel (la facture est
 * certifiée hors plateforme puis saisie, RG-FNE-03) ni pour un mode dont le service n'est pas encore
 * disponible (Sell Flow).
 */
class FneCertifiers
{
    public function __construct(
        private readonly Repository $config,
        private readonly Container $container,
    ) {}

    public function for(CertificationMode $mode): ?FneCertifier
    {
        $driver = $this->config->get("integrations.fne.drivers.{$mode->value}");
        $certifier = is_string($driver) && class_exists($driver) ? $this->container->make($driver) : null;

        return $certifier instanceof FneCertifier ? $certifier : null;
    }

    /**
     * Le mode est pris en charge par la plateforme (manuel, ou service avec pilote).
     */
    public function available(CertificationMode $mode): bool
    {
        return $mode === CertificationMode::Manual || $this->for($mode) !== null;
    }
}
