<?php

namespace App\Domain\Trainings\Http\Requests;

use App\Domain\Trainings\Enums\Attendance;
use App\Domain\Trainings\Http\Requests\Concerns\OrganizerTraining;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /{network|cga}/trainings/{training}/attendance : liste de présence (F-FOR-04) — pour chaque inscrit,
 * présent ou absent.
 */
class AttendanceRequest extends FormRequest
{
    use OrganizerTraining;

    public function authorize(): bool
    {
        return $this->managesTraining();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'attendance' => ['required', 'array', 'min:1', 'max:'.(int) config('cga.trainings.max_capacity')],
            'attendance.*.registration_id' => ['required', 'string', 'ulid', 'distinct'],
            'attendance.*.status' => ['required', Rule::enum(Attendance::class)],
        ];
    }

    /**
     * @return array<string, Attendance> inscription => présence
     */
    public function marks(): array
    {
        $marks = [];
        foreach ((array) $this->validated('attendance') as $row) {
            $marks[(string) $row['registration_id']] = Attendance::from((string) $row['status']);
        }

        return $marks;
    }
}
