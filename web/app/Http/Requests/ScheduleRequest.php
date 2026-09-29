<?php

namespace App\Http\Requests;

use App\Enums\Category;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class ScheduleRequest extends FormRequest
{
    /**
     * Ownership is checked by SchedulePolicy in the controller.
     */
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
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['required', Rule::enum(Category::class)],
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'priority' => ['required', Rule::in(['low', 'medium', 'high'])],
            'recurrence' => ['required', Rule::in(['none', 'daily', 'weekly'])],
        ];
    }

    public function messages(): array
    {
        return [
            'end_time.after' => 'The end time must be after the start time.',
        ];
    }

    /**
     * Convert the form fields into the columns stored on the schedules table.
     *
     * @return array<string, mixed>
     */
    public function scheduleData(): array
    {
        $date = $this->validated('date');
        $recurrence = $this->validated('recurrence');

        return [
            'title' => $this->validated('title'),
            'description' => $this->validated('description'),
            'category' => $this->validated('category'),
            'priority' => $this->validated('priority'),
            'start_datetime' => Carbon::parse("{$date} {$this->validated('start_time')}"),
            'end_datetime' => Carbon::parse("{$date} {$this->validated('end_time')}"),
            'is_recurring' => $recurrence !== 'none',
            'recurrence_rule' => $recurrence === 'none' ? null : $recurrence,
        ];
    }
}
