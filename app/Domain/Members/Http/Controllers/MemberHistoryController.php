<?php

namespace App\Domain\Members\Http\Controllers;

use App\Domain\Members\Http\Requests\ListMemberHistoryRequest;
use App\Domain\Members\Http\Resources\MemberHistoryResource;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Support\MemberHistory;
use App\Support\Config\SettingsService;
use App\Support\Http\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/v1/cga/members/{member}/history : onglet « Historique » du dossier (F-ADH-03).
 */
class MemberHistoryController extends BaseApiController
{
    public function index(ListMemberHistoryRequest $request, Member $member, MemberHistory $history, SettingsService $settings): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(
            fn (): AnonymousResourceCollection => MemberHistoryResource::collection($history->page($member, $request->perPage($settings), $request->page())),
            action: 'members.history.index',
        );
    }
}
