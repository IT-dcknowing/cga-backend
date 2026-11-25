<?php

namespace App\Domain\Members\Actions;

use App\Domain\Members\Support\MembershipExclusivity;
use App\Domain\Members\Support\Ncc;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Exclusivité bloquante (RG-ADH-05, CR-07, T-22) au moment où un dossier entre au portefeuille CGA :
 * validation de l'adhésion, import d'un dossier actif, bascule Cabinet → CGA, radiation annulée.
 * Un verrou consultatif PostgreSQL sur le NCC, tenu jusqu'à la fin de la transaction, sérialise les
 * entrées concurrentes d'un même NCC dans deux CGA : la seconde voit la première et échoue (409, sans
 * nom de CGA, RG-SEC-02).
 */
class AssertMembershipExclusivity
{
    public function __construct(private readonly MembershipExclusivity $exclusivity) {}

    /**
     * @throws BusinessRuleException membership_exclusive
     * @throws LogicException hors d'une transaction : le verrou ne protégerait rien
     */
    public function execute(string $ncc, string $organizationId): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('AssertMembershipExclusivity doit être appelée dans la transaction de l\'Action.');
        }

        $ncc = Ncc::normalize($ncc);
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [$ncc]);

        if ($this->exclusivity->enrolledElsewhere($ncc, $organizationId)) {
            throw new BusinessRuleException('membership_exclusive');
        }
    }
}
