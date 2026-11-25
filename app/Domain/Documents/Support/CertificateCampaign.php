<?php

namespace App\Domain\Documents\Support;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\AttestationBlock;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Models\Member;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Campagne annuelle d'attestations du CGA de l'espace actif (RG-DOC-06, 07) : les dossiers éligibles
 * sont les dossiers validés et actifs du portefeuille CGA, sans attestation d'origine de l'année ; sont
 * exclus les radiés, les radiations en cours, les dossiers du portefeuille Cabinet et les attestations
 * bloquées (RG-DOC-10), ces dernières listées avec leur motif.
 */
class CertificateCampaign
{
    /**
     * Dossiers qui recevront une attestation de l'année.
     *
     * @return Builder<Member>
     */
    public function eligible(int $year): Builder
    {
        return $this->active()
            ->where('attestation_blocked', false)
            ->whereDoesntHave('documents', static fn (Builder $query) => $query
                ->where('type', DocumentType::MembershipCertificate)
                ->where('fiscal_year', $year)
                ->where('version', 1)
                ->where('status', '<>', DocumentStatus::Cancelled));
    }

    /**
     * Dossiers qui ont déjà leur attestation d'origine de l'année.
     */
    public function issued(int $year): int
    {
        return GeneratedDocument::query()
            ->where('type', DocumentType::MembershipCertificate)
            ->where('fiscal_year', $year)
            ->where('version', 1)
            ->where('status', '<>', DocumentStatus::Cancelled)
            ->distinct()
            ->count('member_id');
    }

    /**
     * Blocages en cours des dossiers qui seraient sinon éligibles, avec leur motif.
     *
     * @return Collection<int, AttestationBlock>
     */
    public function blocked(): Collection
    {
        return AttestationBlock::query()
            ->with('member')
            ->whereNull('unblocked_at')
            ->whereIn('member_id', $this->active()->where('attestation_blocked', true)->select('id'))
            ->orderBy('blocked_at')
            ->get();
    }

    /**
     * @return Builder<Member>
     */
    private function active(): Builder
    {
        return Member::query()
            ->where('portfolio', Portfolio::Cga)
            ->where('status', MemberStatus::Active)
            ->whereNotNull('membership_number');
    }
}
