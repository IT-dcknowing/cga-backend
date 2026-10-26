<?php

namespace App\Domain\Organizations\Http\Requests;

use App\Domain\Organizations\Enums\AgreementStatus;
use App\Domain\Organizations\Models\Organization;
use App\Support\Phones\PhoneNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Fiche minimale d'un CGA au registre, créée (POST) ou modifiée (PUT) par le CNCGA (F-ORG-02) :
 * dénomination, sigle, ville, agrément (en cours ou agréé : numéro et date exigés), téléphone, e-mail.
 */
class CgaRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $current = $this->current();

        return $current === null ? Gate::allows('create', Organization::class) : Gate::allows('update', $current);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(PhoneNormalizer $phones): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'sigle' => ['nullable', 'string', 'max:50'],
            'city' => ['required', 'string', 'max:100'],
            'agreement_status' => ['required', Rule::in([AgreementStatus::Pending->value, AgreementStatus::Granted->value])],
            'agreement_number' => ['nullable', 'required_if:agreement_status,'.AgreementStatus::Granted->value, 'string', 'max:60', Rule::unique('organizations', 'agreement_number')->ignore($this->current())],
            'agreement_date' => ['nullable', 'required_if:agreement_status,'.AgreementStatus::Granted->value, 'date_format:Y-m-d', 'before_or_equal:today'],
            'phone_1' => ['required', 'string', 'max:30', $phones->rule()],
            'email' => ['nullable', 'string', 'email', 'max:255'],
        ];
    }

    public function current(): ?Organization
    {
        $current = $this->route('organization');

        return $current instanceof Organization ? $current : null;
    }
}
