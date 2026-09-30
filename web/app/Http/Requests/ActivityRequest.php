<?php

namespace App\Http\Requests;

use App\Enums\Category;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        ];
    }
}
