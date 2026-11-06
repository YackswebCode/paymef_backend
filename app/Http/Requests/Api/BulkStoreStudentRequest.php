<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class BulkStoreStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'school_id' => ['nullable', 'integer', 'exists:schools,id'],
            'students' => ['required', 'array', 'min:1', 'max:500'],
            'students.*.name' => ['required', 'string', 'max:120'],
            'students.*.email' => ['required', 'email', 'max:255'],
        ];
    }
}
