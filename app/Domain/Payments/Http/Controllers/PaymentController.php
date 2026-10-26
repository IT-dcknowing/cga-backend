<?php

namespace App\Domain\Payments\Http\Controllers;

use App\Domain\Payments\Http\Requests\ListPaymentsRequest;
use App\Domain\Payments\Http\Resources\PaymentResource;
use App\Domain\Payments\Models\MemberPayment;
use App\Support\Config\SettingsService;
use App\Support\Http\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * /api/v1/cga/payments : paiements du centre, dont les déclarations des adhérents à examiner (F-PAY-03).
 */
class PaymentController extends BaseApiController
{
    public function index(ListPaymentsRequest $request, SettingsService $settings): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($request, $settings): AnonymousResourceCollection {
            $payments = QueryBuilder::for(MemberPayment::query()->with(['member', 'proof', 'allocations']), $request->listQuery())
                ->allowedFilters(...$request->queryFilters())
                ->allowedSorts(...$request->sorts())
                ->defaultSort('-created_at')
                ->paginate($request->perPage($settings), ['*'], 'page', $request->page());

            return PaymentResource::collection($payments);
        }, action: 'payments.centre');
    }
}
