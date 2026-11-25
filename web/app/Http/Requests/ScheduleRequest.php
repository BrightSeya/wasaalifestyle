<?php

namespace App\Http\Requests;

use App\Enums\Category;
use App\Support\RecurrenceLimits;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

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
            'is_all_day' => ['sometimes', 'boolean'],
            'end_date' => ['exclude_unless:is_all_day,1', 'required', 'date_format:Y-m-d', 'after_or_equal:date'],
            'start_time' => ['exclude_if:is_all_day,1', 'required', 'date_format:H:i'],
            'end_time' => ['exclude_if:is_all_day,1', 'required', 'date_format:H:i', 'after:start_time'],
            'recommendation_confidence' => ['nullable', 'numeric', 'between:0,1'],
            'priority' => ['required', Rule::in(['low', 'medium', 'high'])],
            'recurrence' => ['required', Rule::in(['none', 'daily', 'weekly'])],
            'recurrence_weekday' => ['sometimes', 'nullable', 'integer', 'between:1,7'],
            'recurrence_ends' => ['exclude_unless:recurrence,daily,weekly', 'required', Rule::in(['on_date', 'after'])],
            'recurrence_until' => ['nullable', 'required_if:recurrence_ends,on_date', 'date_format:Y-m-d', 'after_or_equal:date'],
            'recurrence_count' => ['nullable', 'required_if:recurrence_ends,after', 'integer', 'between:1,367'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $recurrence = $this->input('recurrence');
            if ($this->boolean('is_all_day') && $recurrence !== 'none') {
                $validator->errors()->add('recurrence', 'All-day entries cannot repeat.');
            }
            if (! in_array($recurrence, ['daily', 'weekly'], true) || $validator->errors()->has('date')) {
                return;
            }

            $weekday = $validator->errors()->has('recurrence_weekday') ? null : $this->input('recurrence_weekday');
            $first = RecurrenceLimits::firstOccurrence(
                Carbon::parse($this->input('date'), $this->user()->timezone),
                $recurrence,
                $weekday === null ? null : (int) $weekday,
            );
            $maximumEndDate = RecurrenceLimits::maximumEndDate($first);

            if ($this->input('recurrence_ends') === 'on_date' && ! $validator->errors()->has('recurrence_until')) {
                $lastDate = Carbon::parse($this->input('recurrence_until'), $this->user()->timezone);
                if ($lastDate->lt($first)) {
                    $validator->errors()->add('recurrence_until', 'Choose an end date on or after the first occurrence.');
                } elseif ($lastDate->gt($maximumEndDate)) {
                    $validator->errors()->add('recurrence_until', RecurrenceLimits::ONE_YEAR_ERROR);
                }
            }

            if ($this->input('recurrence_ends') === 'after' && ! $validator->errors()->has('recurrence_count')) {
                $lastOccurrence = RecurrenceLimits::lastOccurrence($first, $recurrence, (int) $this->input('recurrence_count'));
                if ($lastOccurrence->gt($maximumEndDate)) {
                    $validator->errors()->add('recurrence_count', RecurrenceLimits::ONE_YEAR_ERROR);
                }
            }
        });
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
        $startDate = RecurrenceLimits::firstOccurrence(
            Carbon::parse($date, $this->user()->timezone),
            $recurrence,
            $this->validated('recurrence_weekday') === null ? null : (int) $this->validated('recurrence_weekday'),
        );
        $date = $startDate->toDateString();
        $recurrenceEnds = $recurrence === 'none' ? null : $this->validated('recurrence_ends');
        $until = $recurrenceEnds === 'on_date' ? $this->validated('recurrence_until') : null;
        if ($until && $until < $date) {
            throw ValidationException::withMessages(['recurrence_until' => 'The last date must be on or after the first occurrence.']);
        }

        $isAllDay = $this->boolean('is_all_day');
        $startDateTime = $isAllDay
            ? Carbon::parse("{$date} 00:00", $this->user()->timezone)
            : Carbon::parse("{$date} {$this->validated('start_time')}", $this->user()->timezone);
        $endDateTime = $isAllDay
            ? Carbon::parse($this->validated('end_date').' 00:00', $this->user()->timezone)->addDay()
            : Carbon::parse("{$date} {$this->validated('end_time')}", $this->user()->timezone);

        return [
            'title' => $this->validated('title'),
            'description' => $this->validated('description'),
            'category' => $this->validated('category'),
            'priority' => $this->validated('priority'),
            'start_datetime' => $startDateTime,
            'end_datetime' => $endDateTime,
            'is_all_day' => $isAllDay,
            'is_recurring' => $recurrence !== 'none',
            'recurrence_rule' => $recurrence === 'none' ? null : $recurrence,
            'recurrence_end_date' => $until,
            'recurrence_count' => $recurrenceEnds === 'after' ? (int) $this->validated('recurrence_count') : null,
        ];
    }
}
