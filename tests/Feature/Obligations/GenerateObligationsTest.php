<?php

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Obligations\Actions\GenerateObligationInstances;
use App\Domain\Obligations\Actions\MarkDeclarationDone;
use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Enums\ObligationKind;
use App\Domain\Obligations\Enums\ObligationPeriodicity;
use App\Domain\Obligations\Models\MemberObligation;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Obligations\Models\ObligationType;
use App\Domain\Obligations\Support\DueDateCalculator;
use App\Domain\Obligations\Support\ObligationPeriod;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Referentials\Models\Holiday;
use App\Support\Config\SettingsService;
use App\Support\Http\Exceptions\BusinessRuleException;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;

/*
 * Générateur d'échéances (06-IMPLEMENTATION-PLAN étape 4.3, RG-OBL-04 à 06) : périodes, règles
 * d'échéance, report au jour ouvré, horizon paramétrable, idempotence (2 passages, aucun doublon),
 * paiement créé à la déclaration faite. Catalogue d'essai : jamais le référentiel de DC-KNOWING.
 * Jour de référence : lundi 26 octobre 2026.
 */

const GENERATION_DAY = '2026-10-26';

/**
 * @param  array<string, mixed>  $type  attributs de l'obligation du catalogue
 */
function confirmedObligation(array $type = [], array $obligation = [], array $member = []): MemberObligation
{
    return MemberObligation::factory()->create([
        'member_id' => Member::factory()->active()->create($member)->id,
        'obligation_type_id' => ObligationType::factory()->create($type)->id,
        'start_date' => '2026-10-01',
        ...$obligation,
    ]);
}

/**
 * @return array<string, string> période => échéance, pour une obligation attribuée
 */
function generatedDueDates(MemberObligation $obligation, ?InstanceKind $kind = null): array
{
    return Tenancy::bypass(static fn (): array => ObligationInstance::query()
        ->where('member_obligation_id', $obligation->id)
        ->when($kind !== null, static fn ($query) => $query->where('kind', $kind))
        ->orderBy('due_date')
        ->get()
        ->mapWithKeys(static fn (ObligationInstance $instance): array => [$instance->period_label => $instance->due_date->toDateString()])
        ->all(), 'Test : échéances générées.');
}

function generate(string $day = GENERATION_DAY): int
{
    return app(GenerateObligationInstances::class)->execute(CarbonImmutable::parse($day));
}

/**
 * Déclaration marquée faite hors de tout espace (l'API de l'étape 4.4 l'appelle dans l'espace du CGA).
 */
function markDone(ObligationInstance $declaration, ?int $amountDue, ?int $amountGross = null): ?ObligationInstance
{
    return Tenancy::bypass(static fn (): ?ObligationInstance => app(MarkDeclarationDone::class)
        ->execute($declaration, CarbonImmutable::parse('2026-10-12'), $amountDue, $amountGross), 'Test : déclaration faite.');
}

describe('périodes et règles d\'échéance (RG-OBL-04, 06)', function () {
    it('découpe les périodes : mois, trimestre, semestre, année, ponctuelle ; aucune sur événement', function () {
        $day = CarbonImmutable::parse('2026-08-14');

        expect(ObligationPeriod::containing(ObligationPeriodicity::Monthly, $day)?->label)->toBe('2026-08')
            ->and(ObligationPeriod::containing(ObligationPeriodicity::Quarterly, $day)?->label)->toBe('2026-T3')
            ->and(ObligationPeriod::containing(ObligationPeriodicity::Quarterly, $day)?->end->toDateString())->toBe('2026-09-30')
            ->and(ObligationPeriod::containing(ObligationPeriodicity::HalfYearly, $day)?->label)->toBe('2026-S2')
            ->and(ObligationPeriod::containing(ObligationPeriodicity::HalfYearly, CarbonImmutable::parse('2026-03-01'))?->end->toDateString())->toBe('2026-06-30')
            ->and(ObligationPeriod::containing(ObligationPeriodicity::Yearly, $day)?->label)->toBe('2026')
            ->and(ObligationPeriod::containing(ObligationPeriodicity::OneOff, $day)?->label)->toBe('2026-08-14')
            ->and(ObligationPeriod::containing(ObligationPeriodicity::OneOff, $day)?->next(ObligationPeriodicity::OneOff))->toBeNull()
            ->and(ObligationPeriod::containing(ObligationPeriodicity::OnEvent, $day))->toBeNull()
            ->and(ObligationPeriod::containing(ObligationPeriodicity::Monthly, $day)?->next(ObligationPeriodicity::Monthly)?->label)->toBe('2026-09');
    });

    it('RG-OBL-06 · applique la règle puis reporte au jour ouvré : samedi, dimanche, jour férié ; mois plus courts', function () {
        Holiday::factory()->create(['date' => '2026-12-15']);
        $calculator = new DueDateCalculator;
        $month = static fn (string $day): ObligationPeriod => ObligationPeriod::containing(ObligationPeriodicity::Monthly, CarbonImmutable::parse($day));

        expect($calculator->dueDate(['type' => 'day_of_next_month', 'day' => 15], $month('2026-09-10'))->toDateString())->toBe('2026-10-15')
            // Dimanche 15 novembre : lundi 16.
            ->and($calculator->dueDate(['type' => 'day_of_next_month', 'day' => 15], $month('2026-10-10'))->toDateString())->toBe('2026-11-16')
            // Mardi 15 décembre férié : mercredi 16.
            ->and($calculator->dueDate(['type' => 'day_of_next_month', 'day' => 15], $month('2026-11-10'))->toDateString())->toBe('2026-12-16')
            // Le 31 en février : le 28 (dimanche), puis lundi 1er mars.
            ->and($calculator->ruleDate(['type' => 'day_of_next_month', 'day' => 31], CarbonImmutable::parse('2027-01-31'))->toDateString())->toBe('2027-02-28')
            ->and($calculator->dueDate(['type' => 'day_of_next_month', 'day' => 31], $month('2027-01-10'))->toDateString())->toBe('2027-03-01')
            ->and($calculator->dueDate(['type' => 'day_after_period_end', 'days' => 20], ObligationPeriod::containing(ObligationPeriodicity::Quarterly, CarbonImmutable::parse('2026-08-01')))->toDateString())->toBe('2026-10-20')
            // 30 janvier 2027, samedi : lundi 1er février.
            ->and($calculator->dueDate(['type' => 'fixed_date', 'month' => 1, 'day' => 30], ObligationPeriod::containing(ObligationPeriodicity::Yearly, CarbonImmutable::parse('2026-05-01')))->toDateString())->toBe('2027-02-01')
            // Date fixe dans l'année même, après la fin d'un semestre.
            ->and($calculator->ruleDate(['type' => 'fixed_date', 'month' => 8, 'day' => 31], CarbonImmutable::parse('2026-06-30'))->toDateString())->toBe('2026-08-31');

        expect(fn () => $calculator->ruleDate(['type' => 'inconnue'], CarbonImmutable::parse('2026-06-30')))->toThrow(InvalidArgumentException::class);
    });
});

describe('génération (RG-OBL-04)', function () {
    it('RG-OBL-04 · génère les échéances mensuelles jusqu\'à l\'horizon de 3 mois, depuis le début de l\'obligation', function () {
        $obligation = confirmedObligation();

        expect(generate())->toBe(4)
            ->and(generatedDueDates($obligation))->toBe([
                '2026-09' => '2026-10-15', '2026-10' => '2026-11-16', '2026-11' => '2026-12-15', '2026-12' => '2027-01-15',
            ]);

        $instance = Tenancy::bypass(static fn () => ObligationInstance::query()->where('period_label', '2026-10')->sole(), 'Test.');
        expect($instance->kind)->toBe(InstanceKind::Declaration)
            ->and($instance->status)->toBe(InstanceStatus::Todo)
            ->and($instance->label)->toBe($obligation->type->label)
            ->and($instance->period_start->toDateString())->toBe('2026-10-01')
            ->and($instance->period_end->toDateString())->toBe('2026-10-31')
            ->and($instance->organization_id)->toBe($obligation->organization_id);
    });

    it('RG-OBL-04 · rejouable : relancer la tâche deux fois ne crée aucun doublon ; un jour plus tard, seule la nouvelle période', function () {
        $obligation = confirmedObligation();

        expect(generate())->toBe(4)
            ->and(generate())->toBe(0)
            ->and(generate('2026-11-20'))->toBe(1)
            ->and(array_keys(generatedDueDates($obligation)))->toBe(['2026-09', '2026-10', '2026-11', '2026-12', '2027-01']);

        $this->artisan('obligations:generate')->expectsOutputToContain('échéance(s) d\'obligation créée(s)')->assertSuccessful();
    });

    it('horizon paramétrable par CGA', function () {
        $obligation = confirmedObligation();
        app(SettingsService::class)->set('obligations.horizon_months', 1, $obligation->organization_id);

        generate();

        expect(array_keys(generatedDueDates($obligation)))->toBe(['2026-09', '2026-10']);
    });

    it('nature de l\'échéance : paiement à payer, diligence non établie ; « déclaration puis paiement » : la déclaration seule', function () {
        $payment = confirmedObligation(['kind' => ObligationKind::Payment]);
        $diligence = confirmedObligation(['kind' => ObligationKind::Diligence]);
        $both = confirmedObligation(['kind' => ObligationKind::DeclarationPayment]);

        generate();

        $first = static fn (MemberObligation $obligation): ObligationInstance => Tenancy::bypass(static fn () => ObligationInstance::query()->where('member_obligation_id', $obligation->id)->orderBy('due_date')->first(), 'Test.');
        expect($first($payment)->status)->toBe(InstanceStatus::ToPay)
            ->and($first($diligence)->status)->toBe(InstanceStatus::NotEstablished)
            ->and($first($both)->kind)->toBe(InstanceKind::Declaration)
            ->and(generatedDueDates($both, InstanceKind::Payment))->toBe([]);
    });

    it('trimestre et année : règles « jours après la fin » et « date fixe » dans l\'horizon', function () {
        $quarterly = confirmedObligation(['periodicity' => ObligationPeriodicity::Quarterly, 'due_rule' => ['type' => 'day_after_period_end', 'days' => 20]]);
        $yearly = confirmedObligation(['periodicity' => ObligationPeriodicity::Yearly, 'due_rule' => ['type' => 'fixed_date', 'month' => 1, 'day' => 30]]);

        generate();
        expect(generatedDueDates($quarterly))->toBe(['2026-T3' => '2026-10-20', '2026-T4' => '2027-01-20'])
            ->and(generatedDueDates($yearly))->toBe([]);

        app(SettingsService::class)->set('obligations.horizon_months', 4, $yearly->organization_id);
        generate();
        expect(generatedDueDates($yearly))->toBe(['2026' => '2027-02-01']);
    });

    it('n\'en génère pas : proposition non confirmée, obligation retirée ou sur événement, dossier brouillon ou radié, CGA résilié', function () {
        $suggested = MemberObligation::factory()->suggested()->create(['start_date' => '2026-10-01']);
        $removed = confirmedObligation(obligation: ['status' => 'removed', 'end_date' => '2026-10-02']);
        $onEvent = confirmedObligation(['periodicity' => ObligationPeriodicity::OnEvent]);
        $draft = confirmedObligation(member: ['status' => MemberStatus::Draft]);
        $radiated = confirmedObligation(member: ['status' => MemberStatus::Radiated]);
        $terminated = confirmedObligation();
        Tenancy::bypass(static function () use ($terminated): void {
            $organization = $terminated->member->organization;
            $organization->status = OrganizationStatus::Terminated;
            $organization->save();
        }, 'Test : CGA résilié.');

        expect(generate())->toBe(0);

        foreach ([$suggested, $removed, $onEvent, $draft, $radiated, $terminated] as $obligation) {
            expect(generatedDueDates($obligation))->toBe([]);
        }
    });

    it('une obligation qui prend fin ne génère plus les périodes qui suivent sa fin', function () {
        $obligation = confirmedObligation(obligation: ['end_date' => '2026-10-31']);

        generate();

        expect(array_keys(generatedDueDates($obligation)))->toBe(['2026-09', '2026-10']);
    });
});

describe('tâche obligations:generate (RG-OBL-04)', function () {
    it('RG-OBL-04 · planifiée chaque nuit à 01:00 : crée les échéances du jour, puis aucune au second passage', function () {
        confirmedObligation();
        $this->travelTo(GENERATION_DAY.' 01:00:00');

        $this->artisan('obligations:generate')->assertSuccessful()->expectsOutputToContain('4 échéance(s) d\'obligation créée(s).');
        $this->artisan('obligations:generate')->assertSuccessful()->expectsOutputToContain('0 échéance(s) d\'obligation créée(s).');

        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'obligations:generate'));
        expect($events)->toHaveCount(1)
            ->and($events->first()->expression)->toBe('0 1 * * *');
    });

    it('journalise puis relance une erreur de la génération', function () {
        Log::spy();
        $this->mock(GenerateObligationInstances::class)->shouldReceive('execute')->andThrow(new RuntimeException('panne'));

        expect(fn () => $this->artisan('obligations:generate')->run())->toThrow(RuntimeException::class, 'panne');
        Log::shouldHaveReceived('error')->once();
    });
});

describe('déclaration faite (RG-OBL-05)', function () {
    it('RG-OBL-05 · une déclaration faite crée le paiement de la période, avec son montant ; rejouable', function () {
        $obligation = confirmedObligation(['kind' => ObligationKind::DeclarationPayment]);
        generate();
        $declaration = Tenancy::bypass(static fn () => ObligationInstance::query()->where('member_obligation_id', $obligation->id)->where('period_label', '2026-09')->sole(), 'Test.');

        $payment = markDone($declaration, 85_000);

        expect($payment?->kind)->toBe(InstanceKind::Payment)
            ->and($payment?->parent_instance_id)->toBe($declaration->id)
            ->and($payment?->period_label)->toBe('2026-09')
            ->and($payment?->due_date->toDateString())->toBe('2026-10-15')
            ->and($payment?->amount_due)->toBe(85_000)
            ->and($payment?->amount_gross)->toBeNull()
            ->and($payment?->status)->toBe(InstanceStatus::ToPay)
            ->and(Tenancy::bypass(static fn () => $declaration->refresh(), 'Test.')->status)->toBe(InstanceStatus::Done)
            ->and($declaration->done_at?->toDateString())->toBe('2026-10-12');

        expect(fn () => markDone($declaration, 85_000))
            ->toThrow(fn (BusinessRuleException $exception) => expect($exception->errorCode)->toBe('instance_already_done'));
        expect(fn () => markDone($payment, 1))
            ->toThrow(fn (BusinessRuleException $exception) => expect($exception->errorCode)->toBe('instance_not_declaration'));
    });

    it('TEE : montant avant et après abattement ; montant nul : payé d\'emblée ; simple déclaration : aucun paiement', function () {
        $tee = confirmedObligation(['kind' => ObligationKind::DeclarationPayment, 'has_abatement' => true]);
        $zero = confirmedObligation(['kind' => ObligationKind::DeclarationPayment]);
        $simple = confirmedObligation(['kind' => ObligationKind::Declaration]);
        $noPayment = confirmedObligation(['kind' => ObligationKind::DeclarationPayment, 'creates_payment_on_done' => false]);
        generate();
        $first = static fn (MemberObligation $obligation): ObligationInstance => Tenancy::bypass(static fn () => ObligationInstance::query()->where('member_obligation_id', $obligation->id)->orderBy('due_date')->first(), 'Test.');

        $teePayment = markDone($first($tee), 60_000, 100_000);
        expect($teePayment?->amount_gross)->toBe(100_000)->and($teePayment?->amount_due)->toBe(60_000);

        expect(markDone($first($zero), 0)?->status)->toBe(InstanceStatus::Paid)
            ->and(markDone($first($simple), null))->toBeNull()
            ->and(markDone($first($noPayment), 5_000))->toBeNull();
    });
});
