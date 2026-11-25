<?php

namespace Tests\Support;

use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Members\Models\Member;
use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Models\MemberObligation;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Referentials\Models\TaxOffice;
use App\Domain\Reports\Enums\CgaResourceCategory;
use App\Domain\Reports\Models\CgaPerson;
use App\Domain\Reports\Models\CgaResource;
use App\Domain\Reports\Models\QuarterlyReport;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Centre et rapport prêts à générer (tests du rapport trimestriel, étapes 5.4, 5.10 et 5.11) : un TEE à
 * Adjamé (30 000 dus au trimestre, 20 000 payés), un TEE et un RME à Plateau ; rapport du T3 2026 complet.
 */
final class ReportGeneration
{
    public static function centre(): Membership
    {
        $cga = Organization::factory()->profileComplete()->create(['numbering_abbreviation' => 'GEN', 'said' => 'SAID Plateau', 'history_text' => 'Créé en 2004.', 'previous_year_revenue' => 48_000_000]);
        $manager = Membership::factory()->role(Role::CgaManager)->create(['organization_id' => $cga->id]);
        CgaPerson::factory()->create(['organization_id' => $cga->id, 'full_name' => 'Awa Koné']);
        foreach (CgaResourceCategory::cases() as $category) {
            CgaResource::factory()->create(['organization_id' => $cga->id, 'category' => $category, 'quantity' => 2]);
        }

        [$adjame, $plateau] = [TaxOffice::factory()->create(['name' => 'CDI Adjamé']), TaxOffice::factory()->create(['name' => 'CDI Plateau'])];
        $tee = Member::factory()->active()->create(['organization_id' => $cga->id, 'company_name' => 'BOUTIQUE ADJAME', 'tax_office_id' => $adjame->id, 'land_section' => 'S1', 'land_parcel' => 'P2']);
        Member::factory()->active()->create(['organization_id' => $cga->id, 'company_name' => 'ATELIER PLATEAU', 'tax_office_id' => $plateau->id]);
        Member::factory()->active()->create(['organization_id' => $cga->id, 'company_name' => 'SARL RME PLATEAU', 'regime' => TaxRegime::Rme, 'tax_office_id' => $plateau->id]);
        $instance = ObligationInstance::factory()->create([
            'member_obligation_id' => MemberObligation::factory()->create(['member_id' => $tee->id])->id, 'kind' => InstanceKind::Payment, 'tax_nature' => 'tee',
            'period_label' => '2026-T3', 'period_start' => '2026-07-01', 'period_end' => '2026-09-30', 'due_date' => '2026-09-15',
            'amount_gross' => 60_000, 'amount_due' => 30_000, 'months_covered' => 3, 'status' => InstanceStatus::PartiallyPaid,
        ]);
        DB::table('obligation_payments')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $cga->id, 'instance_id' => $instance->id, 'member_id' => $tee->id, 'tax_nature' => 'tee',
            'amount' => 20_000, 'paid_on' => '2026-09-10', 'source' => 'cga_entry', 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $manager;
    }

    public static function report(Membership $manager): QuarterlyReport
    {
        $id = inSpace($manager)->postJson('/api/v1/cga/quarterly-reports', ['year' => 2026, 'quarter' => 3])->assertCreated()->json('data.id');
        $director = Tenancy::bypass(static fn () => CgaPerson::query()->where('organization_id', $manager->organization_id)->firstOrFail(), 'Test.');
        $content = [];
        foreach ((array) config('reports.content') as $section) {
            foreach ($section as $key => $definition) {
                $content[$key] = $definition['type'] === 'integer' ? 1 : 'Néant';
            }
        }
        inSpace($manager)->patchJson("/api/v1/cga/quarterly-reports/{$id}", [
            'content' => [...$content, 'trainings' => 'Deux sessions FNE'],
            'people_taxes' => [['person_id' => $director->id, 'its' => 120_000, 'igr' => 45_000, 'cnps' => 60_000]],
            'qualitative_analysis' => ['synthesis' => 'Trimestre stable.'],
            'report_date' => '2026-10-20',
            'regional_directorate_label' => 'DR Abidjan Nord',
        ])->assertOk()->assertJsonPath('data.can.generate', true);

        return Tenancy::bypass(static fn () => QuarterlyReport::query()->findOrFail($id), 'Test.');
    }
}
