<?php

namespace App\Http\Requests;

use App\Enums\ReviewGrade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GradeCardRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'grade' => ['required', Rule::enum(ReviewGrade::class)],
            // Measured by the review screen. Capped at an hour: anything longer
            // means the tab was left open, not that the answer took that long.
            'duration_ms' => ['nullable', 'integer', 'min:0', 'max:3600000'],
        ];
    }

    public function grade(): ReviewGrade
    {
        return ReviewGrade::from((int) $this->integer('grade'));
    }
}
