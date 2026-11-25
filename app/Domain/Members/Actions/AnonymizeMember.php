<?php

namespace App\Domain\Members\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\MemberPiece;
use App\Support\Files\StorageService;

/**
 * Anonymise un dossier radié ou clos depuis la durée de conservation (PRD §7 : 10 ans, `retention.radiated_member_years`).
 * Les données qui désignent l'entreprise et son dirigeant sont effacées ; les montants, régimes, dates et numéros du
 * dossier restent pour les agrégats et les documents légaux encore conservés. Les pièces justificatives partent avec
 * leurs fichiers. L'enregistrement est silencieux pour que le journal d'audit ne recopie pas les valeurs effacées ;
 * l'audit consigne l'événement, sans valeurs. À appeler dans une transaction.
 */
final class AnonymizeMember
{
    /** Valeur technique du téléphone, obligatoire dans le dossier, une fois anonymisé. */
    private const ANONYMIZED_PHONE = '+22500000000';

    public function __construct(
        private readonly StorageService $storage,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(Member $member): void
    {
        $person = __('retention.anonymized_person');

        $member->company_name = __('retention.anonymized_company');
        $member->sigle = null;
        $member->owner_last_name = $person;
        $member->owner_first_names = $person;
        $member->owner_nationality = null;
        $member->owner_id_document_type = null;
        $member->owner_id_document_number = null;
        $member->phone = self::ANONYMIZED_PHONE;
        $member->whatsapp = null;
        $member->email = null;
        $member->address = null;
        $member->signatory_name = null;
        $member->signatory_title = null;
        $member->anonymized_at = now()->toImmutable();
        $member->saveQuietly();

        $fileIds = MemberPiece::query()->where('member_id', $member->id)->pluck('file_id');
        MemberPiece::query()->where('member_id', $member->id)->delete();
        $this->storage->deleteUnreferenced($fileIds);

        $this->audit->record('member_anonymized', $member);
    }
}
