<?php

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Members\Models\Member;
use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Models\MemberObligation;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Referentials\Models\TaxOffice;
use App\Domain\Reports\Support\PortfolioStates;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * États du portefeuille du rapport trimestriel (06-IMPLEMENTATION-PLAN étape 5.2, PRD M09, RG-RPT-01,
 * RG-RPT-08, RG-RPT-11) sur un jeu de données de référence du T3 2026, dont les totaux sont calculés à
 * la main ci-dessous. Arrêté au 30/09/2026, regroupé par CDI, portefeuille CGA seulement.
 */

const STATES_RULE = ['basis' => 'annual_due', 'min_increase_pct' => 0, 'amount' => 'net'];

/**
 * @param  array<string, mixed>  $attributes
 */
function statesMember(Organization $cga, array $attributes): Member
{
    return Member::factory()->active()->create(['organization_id' => $cga->id, 'land_section' => 'S12', 'land_parcel' => 'P34', ...$attributes]);
}

/**
 * Échéance de paiement d'une nature, avec ses paiements confirmés [montant, date].
 *
 * @param  list<array{0: int, 1: string}>  $payments
 */
function statesInstance(Member $member, string $nature, string $due, ?int $gross, ?int $net, ?int $months, array $payments = []): ObligationInstance
{
    $obligation = MemberObligation::factory()->create(['member_id' => $member->id]);
    $instance = ObligationInstance::factory()->create([
        'member_obligation_id' => $obligation->id, 'kind' => InstanceKind::Payment, 'tax_nature' => $nature,
        'period_label' => $due, 'period_start' => $due, 'period_end' => $due, 'due_date' => $due,
        'amount_gross' => $gross, 'amount_due' => $net, 'months_covered' => $months, 'status' => InstanceStatus::ToPay,
    ]);
    foreach ($payments as [$amount, $paidOn]) {
        statesPayment($instance, $nature, $amount, $paidOn);
    }

    return $instance;
}

function statesPayment(ObligationInstance $instance, string $nature, int $amount, string $paidOn, string $status = 'confirmed'): void
{
    DB::table('obligation_payments')->insert([
        'id' => (string) Str::ulid(), 'organization_id' => $instance->organization_id, 'instance_id' => $instance->id,
        'member_id' => $instance->member_id, 'tax_nature' => $nature, 'amount' => $amount, 'paid_on' => $paidOn,
        'source' => 'cga_entry', 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function statesRevenue(Member $member, int $year, int $amount, string $kind = 'actual', string $source = 'entry'): void
{
    DB::table('member_revenues')->insert([
        'id' => (string) Str::ulid(), 'organization_id' => $member->organization_id, 'member_id' => $member->id,
        'year' => $year, 'amount' => $amount, 'kind' => $kind, 'source' => $source, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function statesRegime(Member $member, string $regime, string $effective, string $reason): void
{
    DB::table('member_regime_history')->insert([
        'id' => (string) Str::ulid(), 'organization_id' => $member->organization_id, 'member_id' => $member->id,
        'regime' => $regime, 'effective_date' => $effective, 'reason' => $reason, 'created_at' => now(),
    ]);
}

function statesRadiation(Member $member, string $effective, string $reason): void
{
    $user = User::factory()->create();
    DB::table('radiations')->insert([
        'id' => (string) Str::ulid(), 'organization_id' => $member->organization_id, 'member_id' => $member->id,
        'initiator' => 'cga', 'reason' => $reason, 'status' => 'validated', 'effective_date' => $effective,
        'observation' => 'Jeu de référence', 'requested_by' => $user->id, 'requested_at' => now(), 'validated_by' => $user->id,
        'validated_at' => now(), 'outcome' => 'closed', 'report_year' => (int) substr($effective, 0, 4),
        'report_quarter' => intdiv((int) substr($effective, 5, 2) - 1, 3) + 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * Jeu de référence du T3 2026 (totaux attendus calculés à la main dans chaque test).
 *
 * @return array<string, Member>
 */
function statesDataset(Organization $cga): array
{
    $adjame = TaxOffice::factory()->create(['name' => 'CDI Adjamé']);
    $plateau = TaxOffice::factory()->create(['name' => 'CDI Plateau']);

    // M1 · TEE, Adjamé : 3 échéances 2026 (60 000 brut, 30 000 après abattement, 3 mois) — payée le
    // 10/04 ; 20 000 payés le 12/07 ; payée le 05/10, après l'arrêté. TEE 2025 : 80 000.
    $m1 = statesMember($cga, ['company_name' => 'M1 TEE ADJAME', 'tax_office_id' => $adjame->id, 'adhesion_date' => '2025-05-10', 'cga_contract_signed_on' => '2025-05-10']);
    statesInstance($m1, 'tee', '2026-04-15', 60_000, 30_000, 3, [[30_000, '2026-04-10']]);
    statesInstance($m1, 'tee', '2026-07-15', 60_000, 30_000, 3, [[20_000, '2026-07-12']]);
    statesInstance($m1, 'tee', '2026-09-15', 60_000, 30_000, 3, [[30_000, '2026-10-05']]);
    statesInstance($m1, 'tee', '2025-12-15', 160_000, 80_000, 12);
    statesRevenue($m1, 2025, 12_000_000);
    statesRevenue($m1, 2026, 14_000_000, 'forecast');

    // M2 · TEE, Adjamé, entré le 01/08 (nouveau), sans section ni parcelle.
    $m2 = statesMember($cga, ['company_name' => 'M2 TEE NOUVEAU', 'tax_office_id' => $adjame->id, 'adhesion_date' => '2026-08-01', 'cga_contract_signed_on' => '2026-08-01', 'registration_date' => '2026-07-20', 'land_section' => null, 'land_parcel' => null]);
    statesRevenue($m2, 2026, 5_000_000, 'forecast');

    // M3 · RME, Plateau : patente 100 000, TVA 250 000, FDFP 15 000, ITS 40 000 au T3 ; BIC du T2 et TVA
    // non confirmée écartés.
    $m3 = statesMember($cga, ['company_name' => 'M3 RME PLATEAU', 'regime' => TaxRegime::Rme, 'tax_office_id' => $plateau->id, 'adhesion_date' => '2024-01-10', 'cga_contract_signed_on' => '2024-01-10']);
    $i3 = statesInstance($m3, 'tva', '2026-08-15', null, 1_000_000, null);
    statesPayment($i3, 'patente', 100_000, '2026-07-20');
    statesPayment($i3, 'tva', 250_000, '2026-08-15');
    statesPayment($i3, 'fdfp', 15_000, '2026-09-10');
    statesPayment($i3, 'its', 40_000, '2026-09-12');
    statesPayment($i3, 'bic', 70_000, '2026-06-30');
    statesPayment($i3, 'tva', 99_999, '2026-09-20', 'pending_confirmation');
    statesRevenue($m3, 2025, 40_000_000);

    // M4 · RME sans CDI, entré le 05/07 (nouveau), sans chiffre d'affaires.
    $m4 = statesMember($cga, ['company_name' => 'M4 RME SANS CDI', 'regime' => TaxRegime::Rme, 'tax_office_id' => null, 'adhesion_date' => '2026-07-05', 'cga_contract_signed_on' => '2026-07-05']);

    // M5 · surclassé TEE → RME le 01/08, Plateau : CA 30, 45 et 60 millions (2023 à 2025) ; TEE 2025 :
    // 150 000 ; TEE 2026 : 50 000.
    $m5 = statesMember($cga, ['company_name' => 'M5 SURCLASSE', 'regime' => TaxRegime::Rme, 'regime_since' => '2026-08-01', 'tax_office_id' => $plateau->id, 'adhesion_date' => '2023-02-01', 'cga_contract_signed_on' => '2023-02-01']);
    statesRegime($m5, 'TEE', '2023-02-01', 'Régime initial');
    statesRegime($m5, 'RME', '2026-08-01', 'CA au-delà du seuil TEE');
    statesRevenue($m5, 2023, 30_000_000);
    statesRevenue($m5, 2024, 45_000_000);
    statesRevenue($m5, 2025, 60_000_000);
    statesInstance($m5, 'tee', '2025-06-15', null, 150_000, 12);
    statesInstance($m5, 'tee', '2026-04-15', null, 50_000, 3);

    // M6 · TEE Adjamé radié au 31/08 (cessation) : 40 000 payés sur 80 000 dus en 2026.
    $m6 = statesMember($cga, ['company_name' => 'M6 RADIE', 'tax_office_id' => $adjame->id, 'status' => MemberStatus::Radiated, 'adhesion_date' => '2022-01-01', 'cga_contract_signed_on' => '2022-01-01', 'cga_contract_ended_on' => '2026-08-31']);
    statesInstance($m6, 'tee', '2026-04-15', null, 40_000, 3, [[40_000, '2026-04-01']]);
    statesInstance($m6, 'tee', '2026-07-15', null, 40_000, 3);
    statesRadiation($m6, '2026-08-31', 'cessation');
    statesRevenue($m6, 2025, 8_000_000);

    // M7 · TEE Plateau basculé au Cabinet le 15/10, après l'arrêté : actif au T3 ; montant d'août inconnu.
    $m7 = statesMember($cga, ['company_name' => 'M7 BASCULE APRES', 'portfolio' => Portfolio::Cabinet, 'tax_office_id' => $plateau->id, 'adhesion_date' => '2025-01-01', 'cga_contract_signed_on' => '2025-01-01', 'cga_contract_ended_on' => '2026-10-15']);
    statesInstance($m7, 'tee', '2026-08-15', null, null, 1);
    statesRevenue($m7, 2025, 9_000_000);

    // Écartés : client du Cabinet (même payé), entré après l'arrêté, brouillon, autre CGA.
    $cabinet = statesMember($cga, ['company_name' => 'CABINET', 'portfolio' => Portfolio::Cabinet, 'tax_office_id' => $adjame->id, 'adhesion_date' => '2025-06-01', 'cga_contract_signed_on' => null]);
    statesInstance($cabinet, 'tee', '2026-07-15', null, 99_000, 3, [[99_000, '2026-07-10']]);
    statesMember($cga, ['company_name' => 'APRES ARRETE', 'tax_office_id' => $adjame->id, 'adhesion_date' => '2026-10-02', 'cga_contract_signed_on' => '2026-10-02']);
    Member::factory()->create(['organization_id' => $cga->id, 'company_name' => 'BROUILLON']);
    statesMember(Organization::factory()->create(), ['company_name' => 'AUTRE CGA', 'tax_office_id' => $adjame->id, 'adhesion_date' => '2025-01-01']);

    return compact('m1', 'm2', 'm3', 'm4', 'm5', 'm6', 'm7');
}

/**
 * @return array<string, mixed>
 */
function statesFor(Organization $cga, array $rule = STATES_RULE): array
{
    return Tenancy::bypass(static fn (): array => app(PortfolioStates::class)->compute($cga->id, 2026, 3, $rule), 'Test : états du portefeuille.');
}

/**
 * @param  list<array<string, mixed>>  $groups
 * @return list<string>
 */
function statesNames(array $groups): array
{
    return array_merge(...array_map(static fn (array $group): array => array_column($group['rows'], 'name'), $groups ?: [['rows' => []]]));
}

beforeEach(function () {
    $this->travelTo('2026-10-20 10:00:00');
    $this->cga = Organization::factory()->create();
    $this->members = statesDataset($this->cga);
    $this->states = statesFor($this->cga);
});

it('RG-RPT-11 · arrête le portefeuille CGA au 30/09 : radié après l\'arrêté compris, Cabinet, entrée tardive, brouillon et autre CGA écartés', function () {
    $sheets = $this->states['sheets'];

    expect($this->states['period'])->toBe(['year' => 2026, 'quarter' => 3, 'start' => '2026-07-01', 'end' => '2026-09-30'])
        ->and(statesNames($sheets['tee_follow_up']))->toBe(['M1 TEE ADJAME', 'M2 TEE NOUVEAU', 'M7 BASCULE APRES'])
        ->and(statesNames($sheets['rme_follow_up']))->toBe(['M3 RME PLATEAU', 'M5 SURCLASSE', 'M4 RME SANS CDI'])
        ->and(json_encode($this->states))->not->toContain('CABINET')->not->toContain('APRES ARRETE')->not->toContain('BROUILLON')->not->toContain('AUTRE CGA');
});

it('RG-RPT-01 · Suivi TEE : exigible avant et après abattement, acquitté, mois, reste et taux, sur l\'année et le trimestre', function () {
    [$adjame, $plateau] = $this->states['sheets']['tee_follow_up'];
    $m1 = $adjame['rows'][0];

    // Année jusqu'au 30/09 : 3 échéances (180 000 brut, 90 000 nets), 30 000 + 20 000 payés (le paiement
    // du 05/10 est après l'arrêté), 3 mois (seule l'échéance d'avril est soldée), reste 40 000, 55,6 %.
    // Trimestre : échéances de juillet et septembre — 120 000 brut, 60 000 nets, 20 000 payés, 0 mois.
    expect($adjame['tax_office']['name'])->toBe('CDI Adjamé')
        ->and([$m1['annual_gross'], $m1['annual_net'], $m1['annual_paid'], $m1['annual_months'], $m1['annual_balance'], $m1['annual_rate']])->toBe([180_000, 90_000, 50_000, 3, 40_000, 55.6])
        ->and([$m1['quarter_gross'], $m1['quarter_net'], $m1['quarter_paid'], $m1['quarter_months'], $m1['quarter_balance'], $m1['quarter_rate']])->toBe([120_000, 60_000, 20_000, 0, 40_000, 33.3])
        ->and($m1['reference_revenue'])->toBe(14_000_000)
        ->and($m1['section_parcel'])->toBe('S12 / P34')
        ->and($adjame['totals'])->toMatchArray(['count' => 2, 'annual_net' => 90_000, 'annual_paid' => 50_000, 'quarter_balance' => 40_000])
        ->and($plateau['tax_office']['name'])->toBe('CDI Plateau')
        ->and($plateau['totals'])->toMatchArray(['count' => 1, 'annual_net' => 0, 'annual_paid' => 0]);
});

it('RG-RPT-01 · Suivi RME : paiements confirmés du trimestre par nature, « Autres » avec le FDFP', function () {
    [$plateau, $none] = $this->states['sheets']['rme_follow_up'];
    $m3 = $plateau['rows'][0];

    expect([$m3['patente'], $m3['bic'], $m3['tva'], $m3['its'], $m3['other'], $m3['total']])->toBe([100_000, 0, 250_000, 40_000, 15_000, 405_000])
        ->and($plateau['totals'])->toMatchArray(['count' => 2, 'tva' => 250_000, 'total' => 405_000])
        ->and($none['tax_office'])->toBeNull()
        ->and($none['totals']['count'])->toBe(1);
});

it('RG-RPT-01 · nouveaux du trimestre, surclassement TEE → RME, résiliation TEE', function () {
    $sheets = $this->states['sheets'];
    $upgrade = $sheets['upgrade_tee_rme'][0]['rows'][0];
    $termination = $sheets['terminations_tee'][0]['rows'][0];

    expect(statesNames($sheets['new_tee']))->toBe(['M2 TEE NOUVEAU'])
        ->and($sheets['new_tee'][0]['rows'][0]['registration_date'])->toBe('2026-07-20')
        ->and(statesNames($sheets['new_rme']))->toBe(['M4 RME SANS CDI'])
        ->and($upgrade)->toMatchArray([
            'name' => 'M5 SURCLASSE', 'effective_date' => '2026-08-01', 'reason' => 'CA au-delà du seuil TEE',
            'revenue_n-3' => 30_000_000, 'revenue_n-2' => 45_000_000, 'revenue_n-1' => 60_000_000,
            'dues_n-3' => 0, 'dues_n-2' => 0, 'dues_n-1' => 150_000, 'dues_n' => 50_000,
        ])
        ->and($sheets['upgrade_rme_rsi'])->toBe([])
        ->and($termination)->toMatchArray(['name' => 'M6 RADIE', 'effective_date' => '2026-08-31', 'reason' => 'Cessation d\'activité', 'due' => 80_000, 'paid' => 40_000, 'balance' => 40_000])
        ->and($sheets['terminations_rme'])->toBe([]);
});

it('RG-RPT-08 · TEE augmentée selon la règle du réseau : montant annuel dû N / N-1 par défaut', function () {
    $increased = $this->states['sheets']['increased_tee'];

    // M1 : 90 000 dus en 2026 contre 80 000 en 2025, +12,5 %.
    expect(statesNames($increased))->toBe(['M1 TEE ADJAME'])
        ->and($increased[0]['rows'][0])->toMatchArray(['previous' => 80_000, 'current' => 90_000, 'increase_pct' => 12.5])
        ->and($this->states['sheets']['increased_rme'])->toBe([])
        ->and($this->states['increase_rule'])->toBe(STATES_RULE);
});

it('RG-RPT-08 · la règle change le contenu : seuil de 20 %, avant abattement, chiffre d\'affaires', function () {
    expect(statesFor($this->cga, [...STATES_RULE, 'min_increase_pct' => 20])['sheets']['increased_tee'])->toBe([])
        ->and(statesNames(statesFor($this->cga, [...STATES_RULE, 'amount' => 'gross'])['sheets']['increased_tee']))->toBe(['M1 TEE ADJAME'])
        ->and(statesFor($this->cga, [...STATES_RULE, 'amount' => 'gross'])['sheets']['increased_tee'][0]['rows'][0])->toMatchArray(['previous' => 160_000, 'current' => 180_000])
        ->and(statesNames(statesFor($this->cga, [...STATES_RULE, 'basis' => 'revenue'])['sheets']['increased_tee']))->toBe(['M1 TEE ADJAME'])
        ->and(statesFor($this->cga, [...STATES_RULE, 'basis' => 'quarter_due'])['sheets']['increased_tee'])->toBe([]);
});

it('RG-RPT-04 · Résumé par CDI et total du centre, sans aucun nom d\'adhérent', function () {
    $summary = $this->states['sheets']['summary'];

    expect(array_map(static fn (array $row): ?string => $row['tax_office']['name'] ?? null, $summary['rows']))->toBe(['CDI Adjamé', 'CDI Plateau', null])
        ->and($summary['rows'][0])->toMatchArray(['active_tee' => 2, 'active_rme' => 0, 'new_tee' => 1, 'terminated_tee' => 1, 'active_total' => 2])
        ->and($summary['rows'][1])->toMatchArray(['active_tee' => 1, 'active_rme' => 2, 'new_total' => 0, 'active_total' => 3])
        ->and($summary['rows'][2])->toMatchArray(['active_rme' => 1, 'new_rme' => 1, 'active_total' => 1])
        ->and($summary['totals'])->toBe([
            'active_tee' => 3, 'active_rme' => 3, 'new_tee' => 1, 'new_rme' => 1, 'terminated_tee' => 1, 'terminated_rme' => 0,
            'active_total' => 6, 'new_total' => 2, 'terminated_total' => 1,
        ])
        ->and(json_encode($summary))->not->toContain('M1 TEE')->not->toContain('member_id');
});

it('recettes du trimestre par nature, anomalies et sources des données', function () {
    // Recettes : TEE 20 000 (M1, 12/07) ; patente, TVA, FDFP, ITS de M3 ; le Cabinet est écarté.
    expect($this->states['receipts'])->toBe(['fdfp' => 15_000, 'its' => 40_000, 'patente' => 100_000, 'tee' => 20_000, 'tva' => 250_000, 'total' => 425_000])
        ->and(array_column($this->states['anomalies'], 'code'))->toBe(['missing_tax_office', 'missing_land_parcel', 'missing_reference_revenue', 'unknown_tee_amount'])
        ->and($this->states['anomalies'][1])->toMatchArray(['label' => '1 adhérent TEE sans n° de section et parcelle', 'members' => [['id' => $this->members['m2']->id, 'name' => 'M2 TEE NOUVEAU']]])
        ->and(array_column($this->states['anomalies'][3]['members'], 'name'))->toBe(['M7 BASCULE APRES'])
        // Sources : 8 chiffres d'affaires saisis (M1 : 2, M2, M3, M5 : 3, M7 ; M6, radié, n'est plus suivi),
        // 5 paiements confirmés du trimestre (M1 : 1, M3 : 4).
        ->and($this->states['sources'])->toBe(['revenues' => ['entry' => 8], 'payments' => ['cga_entry' => 5]]);
});

it('RG-RPT-01 · résiliation RME : reste à recouvrer par nature ; ni RSI radié ni changement de régime hors portefeuille CGA', function () {
    $plateau = TaxOffice::factory()->create(['name' => 'CDI Riviera']);

    // RME radié au 15/09 : TVA 300 000 due (payée 100 000), patente 50 000 due (rien payé).
    $rme = statesMember($this->cga, ['company_name' => 'RME RADIE', 'regime' => TaxRegime::Rme, 'tax_office_id' => $plateau->id, 'status' => MemberStatus::Radiated, 'adhesion_date' => '2024-01-01', 'cga_contract_signed_on' => '2024-01-01', 'cga_contract_ended_on' => '2026-09-15']);
    statesInstance($rme, 'tva', '2026-08-15', null, 300_000, null, [[100_000, '2026-08-10']]);
    statesInstance($rme, 'patente', '2026-06-30', null, 50_000, null);
    statesRadiation($rme, '2026-09-15', 'business_sale');
    // RSI radié au 20/09 : hors des feuilles TEE et RME.
    $rsi = statesMember($this->cga, ['company_name' => 'RSI RADIE', 'regime' => TaxRegime::Rsi, 'status' => MemberStatus::Radiated, 'adhesion_date' => '2024-01-01', 'cga_contract_signed_on' => '2024-01-01', 'cga_contract_ended_on' => '2026-09-20']);
    statesRadiation($rsi, '2026-09-20', 'cessation');
    // Client du Cabinet surclassé TEE → RME : il n'était pas au portefeuille CGA, il n'est pas repris.
    $cabinet = statesMember($this->cga, ['company_name' => 'CABINET SURCLASSE', 'portfolio' => Portfolio::Cabinet, 'regime' => TaxRegime::Rme, 'adhesion_date' => '2025-01-01', 'cga_contract_signed_on' => null]);
    statesRegime($cabinet, 'TEE', '2025-01-01', 'Régime initial');
    statesRegime($cabinet, 'RME', '2026-08-10', 'Seuil dépassé');

    $states = statesFor($this->cga);
    $termination = $states['sheets']['terminations_rme'][0]['rows'][0];

    expect($termination)->toMatchArray(['name' => 'RME RADIE', 'reason' => 'Vente ou cession du fonds', 'tva' => 200_000, 'patente' => 50_000, 'total' => 250_000])
        ->and(statesNames($states['sheets']['terminations_tee']))->toBe(['M6 RADIE'])
        ->and(statesNames($states['sheets']['upgrade_tee_rme']))->toBe(['M5 SURCLASSE'])
        ->and($states['sheets']['summary']['totals'])->toMatchArray(['terminated_rme' => 1, 'terminated_total' => 2]);
});

it('un trimestre sans dossier donne des états vides', function () {
    $states = Tenancy::bypass(static fn (): array => app(PortfolioStates::class)->compute(Organization::factory()->create()->id, 2026, 3, STATES_RULE), 'Test.');

    expect($states['sheets']['tee_follow_up'])->toBe([])
        ->and($states['sheets']['summary']['totals']['active_total'])->toBe(0)
        ->and($states['receipts'])->toBe(['total' => 0])
        ->and($states['anomalies'])->toBe([]);
});
