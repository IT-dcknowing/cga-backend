<?php

namespace App\Domain\Statistics\Http\Requests;

use App\Domain\Statistics\Models\StatOrgDaily;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST /network/statistics/brochures : plaquette statistique d'une année (F-STA-03), année en cours ou passée
 * (au plus tôt cga.statistics.first_year) ; statistiques nationales et exports.run.
 */
class ProduceBrochureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('brochure', StatOrgDaily::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'min:'.(int) config('cga.statistics.first_year'), 'max:'.CarbonImmutable::today()->year],
        ];
    }

    public function year(): int
    {
        return (int) $this->validated('year');
    }
}
