<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSchoolRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:120'],
            'slug' => [
                'sometimes', 'string', 'max:120', 'alpha_dash',
                Rule::unique('schools', 'slug')->ignore($this->route('school')?->id),
            ],
            'verification_status' => ['sometimes', Rule::in(['pending', 'verified', 'rejected'])],
        ];
    }
}
