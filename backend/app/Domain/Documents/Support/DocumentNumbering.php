<?php

namespace App\Domain\Documents\Support;

use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Organizations\Models\Organization;
use App\Support\Config\SettingsService;
use App\Support\Http\Exceptions\BusinessRuleException;
use App\Support\Numbering\NumberFormatter;
use App\Support\Numbering\SequenceService;
use LogicException;

/**
 * Numéro d'un document émis (F-DOC-01, 02-TRD §7.1) selon son format paramétré (numbering.*.pattern) :
 * séquence du centre par année, sans doublon ni trou, tirée dans la transaction de l'Action qui émet
 * le document. Le contrat porte le n° d'adhésion du dossier, sans séquence propre ; la facture et son
 * PDF certifié portent le n° FAC tiré à la création de la facture.
 */
class DocumentNumbering
{
    /**
     * Type de document => [paramètre du format, clé de la séquence].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const SEQUENCES = [
        'receipt' => ['numbering.receipt.pattern', 'receipt'],
        'membership_certificate' => ['numbering.certificate.pattern', 'attestation'],
        'radiation_notice' => ['numbering.radiation_notice.pattern', 'notification_letter'],
        // Factures et avoirs partagent la séquence « invoice » du centre (F-PAY-05).
        'invoice' => ['numbering.invoice.pattern', 'invoice'],
        'credit_note' => ['numbering.invoice.pattern', 'invoice'],
    ];

    public function __construct(
        private readonly SequenceService $sequences,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @param  int|null  $year  année du numéro et de la séquence ; défaut : l'année en cours
     *
     * @throws BusinessRuleException abbreviation_missing
     * @throws LogicException type sans numérotation propre
     */
    public function next(string $organizationId, DocumentType $type, ?int $year = null): string
    {
        [$setting, $key] = self::SEQUENCES[$type->value] ?? throw new LogicException("Pas de numérotation propre pour le type {$type->value}.");

        $abbreviation = Organization::query()->whereKey($organizationId)->value('numbering_abbreviation');
        if (! is_string($abbreviation) || $abbreviation === '') {
            throw new BusinessRuleException('abbreviation_missing');
        }
        $year ??= today()->year;

        return NumberFormatter::format((string) $this->settings->get($setting), $this->sequences->next($organizationId, $key, $year), $year, $abbreviation);
    }
}
