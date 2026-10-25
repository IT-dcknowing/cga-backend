<?php

use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Payments\Enums\CertificationMode;
use App\Domain\Payments\Events\InvoiceCertificationFailed;
use App\Domain\Payments\Events\InvoiceRejected;
use App\Domain\Payments\Fne\FakeFneCertifier;
use App\Domain\Payments\Jobs\CertifyInvoice;
use App\Domain\Payments\Models\Invoice;
use App\Domain\Payments\Models\InvoicingProvider;
use App\Domain\Users\Models\Membership;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\Tenancy;
use Database\Seeders\TemplateSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * Certification FNE par le service (06-IMPLEMENTATION-PLAN étape 3.13, RG-FNE-02 à 04, 02-TRD §8.5,
 * §10 bis.1), contre le bouchon FakeFneCertifier, sans aucun appel réseau : facture certifiée avec les
 * éléments renvoyés puis PDF produit ; rejet avec le motif, nouvelle soumission ; service indisponible,
 * nouveaux essais programmés et repris par fne:retry, alerte après le dernier.
 */

beforeEach(function () {
    Storage::fake();
    $this->seed(TemplateSeeder::class);
    config(['queue.default' => 'sync', 'integrations.fne.drivers.dgi_api' => FakeFneCertifier::class]);
    fakePdfConverter();
});

/**
 * Centre en mode « API FNE de la DGI », servi par le bouchon dans le scénario donné.
 *
 * @param  list<string>  $scenarios
 */
function apiCentre(array $scenarios): Membership
{
    app()->instance(FakeFneCertifier::class, new FakeFneCertifier($scenarios));
    $agent = documentAgent();
    $provider = new InvoicingProvider;
    $provider->organization_id = $agent->organization_id;
    $provider->mode = CertificationMode::DgiApi;
    $provider->base_url = 'https://fne.dgi.test';
    $provider->credentials = 'jeton-secret';
    $provider->active = true;
    Tenancy::bypass(fn () => $provider->save(), 'Test : centre en mode API.');

    return $agent;
}

function apiInvoice(Membership $agent, string $id): Invoice
{
    return Tenancy::bypass(fn () => Invoice::query()->with(['certifications', 'document'])->findOrFail($id), 'Test : facture.');
}

it('RG-FNE-02 certifie la facture par le service : éléments de certification enregistrés, puis PDF produit et remis', function () {
    $agent = apiCentre([FakeFneCertifier::ACCEPT]);
    $member = validatedMember($agent, ['company_name' => 'ETS CERTIFIÉE']);

    $id = issueInvoice($agent, $member, [taxedCharge($member, 25_000)->id])->assertCreated()->assertJsonPath('data.certification_mode', 'dgi_api')->json('data.id');

    $invoice = apiInvoice($agent, $id);
    expect($invoice->status->value)->toBe('certified')
        ->and($invoice->fne_number)->toBe('FNE-'.$invoice->number)
        ->and($invoice->fne_stamp)->toBe('CACHET-'.$invoice->number)
        ->and($invoice->certifications)->toHaveCount(1)
        ->and($invoice->certifications[0]->status->value)->toBe('accepted')
        ->and($invoice->certifications[0]->provider_reference)->toBe('REF-'.$invoice->number)
        ->and($invoice->document?->status)->toBe(DocumentStatus::Valid)
        ->and(documentBody(storedDocument((string) $invoice->document_id)))->not->toContain('${')->toContain('FNE-'.$invoice->number)->toContain('ETS CERTIFIÉE');
    invoiceSpace($agent)->getJson("/api/v1/cga/invoices/{$id}")->assertJsonPath('data.certifications.0.status', 'accepted')->assertJsonPath('data.can.deliver', false);
    expect(app(FakeFneCertifier::class)->submitted)->toBe([$invoice->number]);
});

it('RG-FNE-01 rejet : la facture porte le motif retourné, se corrige et se soumet de nouveau sous le même numéro', function () {
    Event::fake([InvoiceRejected::class]);
    $agent = apiCentre([FakeFneCertifier::REJECT, FakeFneCertifier::ACCEPT]);
    $member = validatedMember($agent);
    $id = issueInvoice($agent, $member, [taxedCharge($member, 25_000)->id])->json('data.id');

    invoiceSpace($agent)->getJson("/api/v1/cga/invoices/{$id}")->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.rejection_reason', 'NCC du client inconnu de la DGI.')
        ->assertJsonPath('data.can.resubmit', true)
        ->assertJsonPath('data.pdf', null);
    Event::assertDispatched(InvoiceRejected::class);

    invoiceSpace($agent)->postJson("/api/v1/cga/invoices/{$id}/resubmit")->assertOk();
    $invoice = apiInvoice($agent, $id);
    expect($invoice->status->value)->toBe('certified')
        ->and($invoice->rejection_reason)->toBeNull()
        ->and($invoice->certifications)->toHaveCount(2);
    invoiceSpace($agent)->postJson("/api/v1/cga/invoices/{$id}/resubmit")->assertStatus(409)->assertJsonPath('code', 'invoice_not_resubmittable');
});

it('RG-FNE-04 service indisponible : la facture reste en attente, fne:retry la soumet de nouveau à l\'heure prévue, jusqu\'à la certification', function () {
    $agent = apiCentre([FakeFneCertifier::UNAVAILABLE, FakeFneCertifier::ACCEPT]);
    $member = validatedMember($agent);
    $this->freezeSecond();
    $id = issueInvoice($agent, $member, [taxedCharge($member, 25_000)->id])->assertCreated()->json('data.id');

    $invoice = apiInvoice($agent, $id);
    expect($invoice->status->value)->toBe('pending_certification')
        ->and($invoice->certifications[0]->status->value)->toBe('error')
        ->and($invoice->certifications[0]->next_retry_at?->toIso8601String())->toBe(now()->addSeconds(300)->toIso8601String())
        ->and($invoice->document_id)->toBeNull();
    invoiceSpace($agent)->getJson("/api/v1/cga/invoices/{$id}")->assertJsonPath('data.can.resubmit', false)->assertJsonPath('data.can.cancel', false);

    Artisan::call('fne:retry');
    expect(apiInvoice($agent, $id)->certifications)->toHaveCount(1);

    $this->travel(301)->seconds();
    Artisan::call('fne:retry');
    expect(apiInvoice($agent, $id)->status->value)->toBe('certified')
        ->and(Artisan::output())->toContain('1 facture(s)');
});

it('RG-FNE-04 alerte le gérant après le dernier essai ; la facture se soumet alors de nouveau ou s\'annule', function () {
    Event::fake([InvoiceCertificationFailed::class]);
    app(SettingsService::class)->set('fne.retry.tries', 2);
    app(SettingsService::class)->set('fne.retry.backoff_seconds', [60]);
    $agent = apiCentre([FakeFneCertifier::UNAVAILABLE]);
    $member = validatedMember($agent);
    $charge = taxedCharge($member, 25_000);
    $id = issueInvoice($agent, $member, [$charge->id])->json('data.id');

    $this->travel(61)->seconds();
    Artisan::call('fne:retry');

    $invoice = apiInvoice($agent, $id);
    expect($invoice->certifications)->toHaveCount(2)
        ->and($invoice->certifications[1]->next_retry_at)->toBeNull()
        ->and($invoice->status->value)->toBe('pending_certification')
        ->and($invoice->certificationStalled())->toBeTrue();
    Event::assertDispatched(InvoiceCertificationFailed::class);

    $this->travel(3600)->seconds();
    Artisan::call('fne:retry');
    expect(apiInvoice($agent, $id)->certifications)->toHaveCount(2);

    invoiceSpace($agent)->getJson("/api/v1/cga/invoices/{$id}")->assertJsonPath('data.can.resubmit', true)->assertJsonPath('data.can.cancel', true);
    app()->instance(FakeFneCertifier::class, new FakeFneCertifier([FakeFneCertifier::ACCEPT]));
    invoiceSpace($agent)->postJson("/api/v1/cga/invoices/{$id}/resubmit")->assertOk();
    expect(apiInvoice($agent, $id)->status->value)->toBe('certified');
});

it('garde la certification si le PDF ne peut pas encore être produit, et le remet ensuite à la demande', function () {
    $agent = apiCentre([FakeFneCertifier::ACCEPT]);
    $member = validatedMember($agent);
    Queue::fake();
    $id = issueInvoice($agent, $member, [taxedCharge($member, 25_000)->id])->json('data.id');
    Queue::assertPushedOn('integrations', CertifyInvoice::class, fn (CertifyInvoice $job): bool => $job->invoiceId === $id && $job->uniqueId() === $id);

    Organization::query()->whereKey($agent->organization_id)->update(['stamp_file_id' => null]);
    config(['queue.default' => 'sync']);
    app()->call([new CertifyInvoice($id, $agent->id), 'handle']);

    $invoice = apiInvoice($agent, $id);
    expect($invoice->status->value)->toBe('certified')
        ->and($invoice->document_id)->toBeNull()
        ->and(ActivityLog::query()->where('action', 'invoices.delivery_deferred')->where('error_code', 'cga_profile_incomplete')->exists())->toBeTrue();
    invoiceSpace($agent)->getJson("/api/v1/cga/invoices/{$id}")->assertJsonPath('data.can.deliver', true);
    invoiceSpace($agent)->postJson("/api/v1/cga/invoices/{$id}/deliver")->assertStatus(409)->assertJsonPath('code', 'cga_profile_incomplete');

    Queue::fake();
    Organization::query()->whereKey($agent->organization_id)->update(['stamp_file_id' => Organization::query()->whereKey($agent->organization_id)->value('signature_file_id')]);
    invoiceSpace($agent)->postJson("/api/v1/cga/invoices/{$id}/deliver")->assertStatus(202);
    expect(GeneratedDocument::query()->withoutGlobalScopes()->where('source_id', $id)->count())->toBe(1);
    invoiceSpace($agent)->postJson("/api/v1/cga/invoices/{$id}/deliver")->assertStatus(409)->assertJsonPath('code', 'invoice_not_deliverable');
});

it('ne soumet jamais au service une facture en mode manuel ni une facture déjà certifiée', function () {
    app()->instance(FakeFneCertifier::class, $fake = new FakeFneCertifier([FakeFneCertifier::ACCEPT]));
    $agent = documentAgent();
    $member = validatedMember($agent);
    $id = issueInvoice($agent, $member, [taxedCharge($member, 25_000)->id])->assertJsonPath('data.certification_mode', 'manual')->json('data.id');

    app()->call([new CertifyInvoice($id, $agent->id), 'handle']);

    expect($fake->submitted)->toBe([])
        ->and(apiInvoice($agent, $id)->status->value)->toBe('pending_certification');
});
