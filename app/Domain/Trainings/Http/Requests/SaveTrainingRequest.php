<?php

namespace App\Domain\Trainings\Http\Requests;

use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Trainings\Enums\AudienceType;
use App\Domain\Trainings\Enums\TrainingFormat;
use App\Domain\Trainings\Http\Requests\Concerns\OrganizerTraining;
use App\Domain\Trainings\Models\Training;
use App\Domain\Trainings\Support\TrainingData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /{network|cga}/trainings et PUT …/{training} : une formation (F-FOR-01) — titre, description, dates
 * et horaires, format, lieu ou lien, formateur, places (vide : illimité), prix (0 : gratuite), validation des
 * inscriptions, publics ciblés ; « publish » pour publier dès la création. L'organisateur et le périmètre des
 * publics viennent de l'espace actif.
 */
class SaveTrainingRequest extends FormRequest
{
    use OrganizerTraining;

    public function authorize(): bool
    {
        return $this->route('training') === null
            ? $this->organizerSpace() && Gate::allows('organize', Training::class)
            : $this->managesTraining();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $text = 'max:'.(int) config('cga.trainings.text_max_length');

        $rules = [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', $text],
            'format' => ['required', Rule::enum(TrainingFormat::class)],
            'location' => ['nullable', 'string', 'max:255'],
            'online_url' => ['nullable', 'url:https', 'max:500'],
            'trainer' => ['nullable', 'string', 'max:200'],
            'starts_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('cga.trainings.max_capacity')],
            'price' => ['required', 'integer', 'min:0', 'max:'.(int) config('cga.obligations.max_amount')],
            'requires_approval' => ['required', 'boolean'],
            'audiences' => ['required', 'array', 'min:1', 'max:'.count(AudienceType::cases()) * 2],
            'audiences.*.type' => ['required', Rule::enum(AudienceType::class)],
            'audiences.*.regime' => ['nullable', 'required_if:audiences.*.type,'.AudienceType::MembersByRegime->value, Rule::in([TaxRegime::Rme->value, TaxRegime::Tee->value])],
        ];
        if ($this->route('training') === null) {
            $rules['publish'] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    public function trainingData(): TrainingData
    {
        /** @var list<array{type: string, regime?: string|null}> $audiences */
        $audiences = (array) $this->validated('audiences');
        $unique = [];
        foreach ($audiences as $audience) {
            $type = AudienceType::from($audience['type']);
            $regime = $type === AudienceType::MembersByRegime && isset($audience['regime']) ? TaxRegime::from($audience['regime']) : null;
            $unique[$type->value.'|'.$regime?->value] = ['type' => $type, 'regime' => $regime];
        }

        $capacity = $this->validated('capacity');

        return new TrainingData(
            title: (string) $this->validated('title'),
            description: self::text($this->validated('description')),
            format: TrainingFormat::from((string) $this->validated('format')),
            location: self::text($this->validated('location')),
            onlineUrl: self::text($this->validated('online_url')),
            trainer: self::text($this->validated('trainer')),
            startsAt: CarbonImmutable::parse((string) $this->validated('starts_at')),
            endsAt: CarbonImmutable::parse((string) $this->validated('ends_at')),
            capacity: $capacity === null ? null : (int) $capacity,
            price: (int) $this->validated('price'),
            requiresApproval: (bool) $this->validated('requires_approval'),
            audiences: array_values($unique),
        );
    }

    public function publish(): bool
    {
        return (bool) $this->validated('publish', false);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
