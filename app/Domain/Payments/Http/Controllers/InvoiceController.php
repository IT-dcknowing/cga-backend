<?php

namespace App\Domain\Payments\Http\Controllers;

use App\Domain\Payments\Actions\CancelInvoice;
use App\Domain\Payments\Actions\DeliverCertifiedInvoice;
use App\Domain\Payments\Actions\IssueCreditNote;
use App\Domain\Payments\Actions\IssueInvoice;
use App\Domain\Payments\Actions\RecordManualCertification;
use App\Domain\Payments\Actions\ResubmitInvoice;
use App\Domain\Payments\Http\Requests\CancelInvoiceRequest;
use App\Domain\Payments\Http\Requests\IssueInvoiceRequest;
use App\Domain\Payments\Http\Requests\ListInvoicesRequest;
use App\Domain\Payments\Http\Requests\ManualCertificationRequest;
use App\Domain\Payments\Http\Resources\InvoiceResource;
use App\Domain\Payments\Models\Invoice;
use App\Support\Config\SettingsService;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * /api/v1/cga/invoices : factures et avoirs du centre (F-PAY-05, F-PAY-06, 03-APPFLOW `/cga/factures`)
 * — liste, émission et soumission à la certification FNE, détail, saisie de la certification en mode
 * manuel, nouvelle soumission après un rejet, annulation avant certification, avoir total, remise du
 * PDF certifié s'il n'a pas pu être produit. Aucune facture n'est remise avant sa certification.
 */
class InvoiceController extends BaseApiController
{
    private const WITH = ['member', 'original', 'creditNote', 'certifications', 'document.pdf'];

    public function __construct(private readonly TenantContext $context) {}

    public function index(ListInvoicesRequest $request, SettingsService $settings): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($request, $settings): AnonymousResourceCollection {
            $invoices = QueryBuilder::for(Invoice::query()->with(['member', 'original', 'creditNote', 'document.pdf']), $request->listQuery())
                ->allowedFilters(...$request->queryFilters())
                ->allowedSorts(...$request->sorts())
                ->defaultSort('-created_at')
                ->paginate($request->perPage($settings), ['*'], 'page', $request->page());

            return InvoiceResource::collection($invoices);
        }, action: 'invoices.index');
    }

    public function store(IssueInvoiceRequest $request, IssueInvoice $issue): JsonResponse
    {
        return $this->handle(function () use ($request, $issue): JsonResponse {
            $member = $request->member();
            Gate::authorize('issue', [Invoice::class, $member]);
            $invoice = $issue->execute($member, $request->chargeIds(), $this->context->membership());
            $this->logSubject($invoice);

            return (new InvoiceResource($invoice->load(self::WITH)))->response()->setStatusCode(201);
        }, action: 'invoices.store');
    }

    public function show(Invoice $invoice): InvoiceResource|JsonResponse
    {
        return $this->handle(function () use ($invoice): InvoiceResource {
            Gate::authorize('view', $invoice);

            return new InvoiceResource($invoice->load(self::WITH));
        }, action: 'invoices.show');
    }

    public function certify(ManualCertificationRequest $request, Invoice $invoice, RecordManualCertification $record): InvoiceResource|JsonResponse
    {
        return $this->handle(function () use ($request, $invoice, $record): InvoiceResource {
            return new InvoiceResource($record->execute($invoice, $request->fneNumber(), $request->pdf(), $this->context->membership())->load(self::WITH));
        }, action: 'invoices.certify');
    }

    public function resubmit(Invoice $invoice, ResubmitInvoice $resubmit): InvoiceResource|JsonResponse
    {
        return $this->handle(function () use ($invoice, $resubmit): InvoiceResource {
            Gate::authorize('manage', $invoice);

            return new InvoiceResource($resubmit->execute($invoice, $this->context->membership())->load(self::WITH));
        }, action: 'invoices.resubmit');
    }

    public function cancel(CancelInvoiceRequest $request, Invoice $invoice, CancelInvoice $cancel): InvoiceResource|JsonResponse
    {
        return $this->handle(function () use ($request, $invoice, $cancel): InvoiceResource {
            return new InvoiceResource($cancel->execute($invoice, $request->reason())->load(self::WITH));
        }, action: 'invoices.cancel');
    }

    public function creditNote(Invoice $invoice, IssueCreditNote $issue): JsonResponse
    {
        return $this->handle(function () use ($invoice, $issue): JsonResponse {
            Gate::authorize('manage', $invoice);
            $creditNote = $issue->execute($invoice, $this->context->membership());
            $this->logSubject($creditNote);

            return (new InvoiceResource($creditNote->load(self::WITH)))->response()->setStatusCode(201);
        }, action: 'invoices.credit_note');
    }

    public function deliver(Invoice $invoice, DeliverCertifiedInvoice $deliver): JsonResponse
    {
        return $this->handle(function () use ($invoice, $deliver): JsonResponse {
            Gate::authorize('manage', $invoice);

            return (new InvoiceResource($deliver->execute($invoice, $this->context->membership())->load(self::WITH)))->response()->setStatusCode(202);
        }, action: 'invoices.deliver');
    }
}
