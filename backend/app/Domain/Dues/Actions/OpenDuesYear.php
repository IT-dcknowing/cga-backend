<?php

namespace App\Domain\Dues\Actions;

use App\Domain\Dues\Enums\DuesStatus;
use App\Domain\Dues\Models\NetworkDue;
use App\Domain\Dues\Support\DuesRules;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Models\Organization;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * `dues:open-year` le 1er janvier (F-COT-01, 02-TRD §11) : une cotisation de l'année pour chaque CGA du
 * registre, abonné ou non, non résilié — montant de l'année (dues.amount), prorata semestriel
 * (RG-COT-02), date butoir (RG-COT-01). Rejouable : un CGA ajouté au registre depuis reçoit la sienne,
 * aucun n'en a deux.
 */
class OpenDuesYear
{
    public function __construct(
        private readonly DuesRules $rules,
        private readonly SettingsService $settings,
    ) {}

    public function execute(int $year): int
    {
        return Tenancy::bypass(function () use ($year): int {
            $created = 0;
            $base = (int) $this->settings->get('dues.amount');
            $deadline = $this->rules->deadline($year);
            Organization::query()
                ->where('type', OrganizationType::Cga)
                ->where('status', '<>', OrganizationStatus::Terminated)
                ->whereNotIn('id', NetworkDue::query()->where('year', $year)->select('organization_id'))
                ->orderBy('id')
                ->each(function (Organization $cga) use ($year, $base, $deadline, &$created): void {
                    DB::transaction(function () use ($cga, $year, $base, $deadline): void {
                        $ratio = $this->rules->prorata($cga, $year);
                        $due = new NetworkDue;
                        $due->organization_id = $cga->id;
                        $due->year = $year;
                        $due->base_amount = $base;
                        $due->prorata_ratio = number_format($ratio, 3, '.', '');
                        $due->amount_due = (int) round($base * $ratio);
                        $due->deadline = $deadline;
                        $due->status = DuesStatus::Due;
                        $due->save();
                    });
                    $created++;
                });

            return $created;
        }, 'Tâche dues:open-year : cotisation de l\'année de chaque CGA du registre (F-COT-01).');
    }
}
