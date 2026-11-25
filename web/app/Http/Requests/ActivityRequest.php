<?php

namespace App\Http\Requests;

use App\Enums\Category;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ActivityRequest extends FormRequest
{
    /**
     * Ownership is checked by ActivityPolicy in the controller.
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
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::enum(Category::class)],
            'preferred_time_of_day' => ['required', 'date_format:H:i'],
            'frequency' => ['required', 'integer', 'between:1,7'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:600'],
            'weekly_slots' => ['required', 'array'],
            'weekly_slots.*' => ['required', 'array'],
            'weekly_slots.*.weekday' => ['required', 'integer', 'between:1,7'],
            'weekly_slots.*.start_time' => ['required', 'date_format:H:i'],
            'weekly_slots.*.confidence' => ['nullable', 'numeric', 'between:0,1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $slots = $this->input('weekly_slots');
            $frequency = $this->input('frequency');
            if (! is_array($slots) || ! is_numeric($frequency) || $validator->errors()->has('frequency')) {
                return;
            }

            if (count($slots) !== (int) $frequency) {
                $validator->errors()->add('weekly_slots', 'Choose exactly one weekly time for each activity session.');
            }

            $weekdays = collect($slots)
                ->filter(fn ($slot) => is_array($slot) && is_numeric($slot['weekday'] ?? null))
                ->map(fn ($slot) => (int) $slot['weekday']);
            if ($weekdays->count() !== $weekdays->unique()->count()) {
                $validator->errors()->add('weekly_slots', 'Choose a different day for each weekly session.');
            }
        });
    }
}
