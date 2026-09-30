<?php

namespace App\Http\Requests;

use App\Support\UserPreferences;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
            'peak_start_hour' => ['required', 'integer', 'between:0,23'],
            'peak_end_hour' => ['required', 'integer', 'between:0,23', 'gt:peak_start_hour'],
            'preferred_break_minutes' => ['required', 'integer', 'between:5,120'],
            'notify_conflicts' => ['sometimes', 'boolean'],
            'notify_daily_summary' => ['sometimes', 'boolean'],
            'notify_recommendations' => ['sometimes', 'boolean'],
        ];

        foreach (UserPreferences::days() as $day) {
            foreach (UserPreferences::periods() as $period) {
                $rules["availability.{$day}.{$period}"] = ['sometimes', 'boolean'];
            }
        }

        return $rules;
    }

    /**
     * Build the preferences object from validated input. Unchecked checkboxes are absent
     * from the request, so boolean() reads them as false.
     */
    public function preferences(): UserPreferences
    {
        $availability = [];
        foreach (UserPreferences::days() as $day) {
            foreach (UserPreferences::periods() as $period) {
                $availability[$day][$period] = $this->boolean("availability.{$day}.{$period}");
            }
        }

        return new UserPreferences(
            availability: $availability,
            peakStartHour: (int) $this->validated('peak_start_hour'),
            peakEndHour: (int) $this->validated('peak_end_hour'),
            preferredBreakMinutes: (int) $this->validated('preferred_break_minutes'),
            notifyConflicts: $this->boolean('notify_conflicts'),
            notifyDailySummary: $this->boolean('notify_daily_summary'),
            notifyRecommendations: $this->boolean('notify_recommendations'),
        );
    }
}
