<?php

namespace App\Http\Requests;

use App\Support\UserPreferences;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'availability' => ['sometimes', 'array'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'availability.*' => ['sometimes', 'array', 'max:8'],
            'availability.*.*.start' => ['nullable', 'date_format:H:i'],
            'availability.*.*.end' => ['nullable', 'date_format:H:i'],
            'peak_start_hour' => ['required', 'integer', 'between:0,23'],
            'peak_end_hour' => ['required', 'integer', 'between:0,23', 'gt:peak_start_hour'],
            'preferred_break_minutes' => ['required', 'integer', 'between:5,120'],
            'notify_conflicts' => ['sometimes', 'boolean'],
            'notify_recommendations' => ['sometimes', 'boolean'],
            'personalize_recommendations' => ['sometimes', 'boolean'],
            'contribute_shared_recommendations' => ['sometimes', 'boolean'],
        ];

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $availability = $this->input('availability', []);
            if (! is_array($availability)) {
                return;
            }

            foreach ($availability as $day => $ranges) {
                if (! in_array($day, UserPreferences::days(), true) || ! is_array($ranges)) {
                    continue;
                }

                $validRanges = [];
                foreach ($ranges as $index => $range) {
                    if (! is_array($range)) {
                        continue;
                    }
                    $start = $range['start'] ?? '';
                    $end = $range['end'] ?? '';
                    if ($start === '' && $end === '') {
                        continue;
                    }
                    if ($start === '' || $end === '') {
                        $validator->errors()->add("availability.{$day}.{$index}.end", 'Enter both a start and end time.');

                        continue;
                    }
                    if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $start)
                        || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $end)) {
                        continue;
                    }
                    if ($start >= $end) {
                        $validator->errors()->add("availability.{$day}.{$index}.end", 'End time must be later than start time.');

                        continue;
                    }
                    $validRanges[] = ['start' => $start, 'end' => $end, 'index' => $index];
                }

                usort($validRanges, fn ($a, $b) => strcmp($a['start'], $b['start']));
                for ($index = 1; $index < count($validRanges); $index++) {
                    if ($validRanges[$index]['start'] < $validRanges[$index - 1]['end']) {
                        $validator->errors()->add(
                            "availability.{$day}.{$validRanges[$index]['index']}.start",
                            'This time range overlaps another range for this day.',
                        );
                    }
                }
            }
        });
    }

    /** Build the typed preferences object from the validated time ranges and settings. */
    public function preferences(): UserPreferences
    {
        $inputAvailability = $this->validated('availability', []);
        $availability = [];
        foreach (UserPreferences::days() as $day) {
            $availability[$day] = collect($inputAvailability[$day] ?? [])
                ->filter(fn ($range) => is_array($range) && ($range['start'] ?? '') !== '' && ($range['end'] ?? '') !== '')
                ->map(fn ($range) => ['start' => $range['start'], 'end' => $range['end']])
                ->values()->all();
        }

        return new UserPreferences(
            availability: $availability,
            peakStartHour: (int) $this->validated('peak_start_hour'),
            peakEndHour: (int) $this->validated('peak_end_hour'),
            preferredBreakMinutes: (int) $this->validated('preferred_break_minutes'),
            notifyConflicts: $this->boolean('notify_conflicts'),
            notifyRecommendations: $this->boolean('notify_recommendations'),
            personalizeRecommendations: $this->boolean('personalize_recommendations'),
        );
    }
}
