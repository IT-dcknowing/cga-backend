<?php

namespace App\Domain\Platform\Http\Requests;

use App\Domain\Users\Enums\Role;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

class CreatePlatformAccessLogRequest extends FormRequest
{
    public function authorize(TenantContext $tenant): bool
    {
        return $tenant->has() && $tenant->role() === Role::PlatformAdmin;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'string', 'exists:organizations,id'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'route' => ['nullable', 'string', 'max:200'],
        ];
    }
}

