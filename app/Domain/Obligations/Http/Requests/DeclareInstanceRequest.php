<?php

namespace App\Domain\Obligations\Http\Requests;

use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Models\MemberObligation;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/v1/member/obligations/{obligationInstance}/declare : « Signaler comme fait / payé » depuis
 * l'espace de l'adhérent (RG-OBL-09) — pour un paiement : montant, date, preuve facultative (multipart).
 * Une ligne d'un autre dossier n'existe pas pour l'adhérent (404).
 */
class DeclareInstanceRequest extends FormRequest
{
    /**
     * @throws ModelNotFoundException ligne d'un autre dossier
     */
    public function authorize(TenantContext $context): bool
    {
        if (! Gate::allows('declare', MemberObligation::class)) {
            return false;
        }
        if ($this->line()->member_id !== $context->memberId()) {
            throw (new ModelNotFoundException)->setModel(ObligationInstance::class);
        }

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(SettingsService $settings): array
    {
        if ($this->line()->kind !== InstanceKind::Payment) {
            return [];
        }

        return [
            'amount' => ['required', 'integer', 'min:1', 'max:'.config('cga.obligations.max_amount')],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'proof' => ['nullable', 'file', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:'.((int) $settings->get('files.max_upload_mb') * 1024)],
        ];
    }

    public function line(): ObligationInstance
    {
        $instance = $this->route('obligationInstance');

        return $instance instanceof ObligationInstance ? $instance : throw (new ModelNotFoundException)->setModel(ObligationInstance::class);
    }

    public function amount(): ?int
    {
        $amount = $this->validated('amount');

        return $amount === null ? null : (int) $amount;
    }

    public function paidOn(): ?CarbonImmutable
    {
        $date = $this->validated('paid_on');

        return is_string($date) ? CarbonImmutable::parse($date) : null;
    }

    public function proof(): ?UploadedFile
    {
        $file = $this->validated('proof');

        return $file instanceof UploadedFile ? $file : null;
    }
}
