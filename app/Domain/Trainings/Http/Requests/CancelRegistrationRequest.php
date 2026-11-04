<?php

namespace App\Domain\Trainings\Http\Requests;

use App\Domain\Trainings\Http\Requests\Concerns\RouteRegistration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST /me/training-registrations/{registration}/cancel (« Se désinscrire ») et
 * POST /cga/training-registrations/{registration}/cancel (un CGA désinscrit une personne de son centre) :
 * jusqu'à J-n (RG-FOR-04).
 */
class CancelRegistrationRequest extends FormRequest
{
    use RouteRegistration;

    public function authorize(): bool
    {
        return Gate::allows($this->routeIs('me.*') ? 'own' : 'cancel', $this->registration());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
