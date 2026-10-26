<?php

namespace App\Domain\Referentials\Http\Requests;

use App\Domain\Referentials\Models\Sector;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/v1/cga/sectors : « Proposer un secteur » (RG-REF-01) — le nom seul ; le code, le statut et
 * le CGA auteur sont fixés par le serveur. Un nom déjà pris par un secteur visible du centre (validé, ou
 * proposé par lui) est refusé, sans distinguer majuscules et minuscules.
 */
class ProposeSectorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('propose', Sector::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(TenantContext $context): array
    {
        return [
            'name' => ['required', 'string', 'max:120', static function (string $attribute, mixed $value, Closure $fail) use ($context): void {
                $taken = is_string($value) && Sector::query()
                    ->visibleIn($context)
                    ->whereLike('name', addcslashes(trim($value), '\\%_'))
                    ->exists();
                if ($taken) {
                    $fail('validation.unique')->translate();
                }
            }],
        ];
    }

    public function name(): string
    {
        return trim((string) $this->validated('name'));
    }
}
