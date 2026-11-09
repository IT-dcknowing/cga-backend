<?php

namespace App\Domain\Platform\Http\Resources;

use App\Domain\Platform\Models\PlatformAccessLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlatformAccessLog
 */
class PlatformAccessLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'admin_user_id' => $this->admin_user_id,
            'admin_name' => $this->relationLoaded('adminUser') && $this->adminUser ? $this->adminUser->full_name : null,
            'organization_id' => $this->organization_id,
            'organization_name' => $this->relationLoaded('organization') && $this->organization ? $this->organization->name : null,
            'reason' => $this->reason,
            'route' => $this->route,
            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
        ];
    }
}

