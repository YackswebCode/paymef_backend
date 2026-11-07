<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class VerifyKycRequest extends FormRequest
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
            'type' => ['required', 'in:bvn,nin'],
            'id_number' => ['required', 'string', 'regex:/^\d{11}$/'],
            'first_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'dob' => ['nullable', 'date', 'before:today'],
            'consent' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'id_number.regex' => 'Enter the 11-digit number exactly as it appears on the document.',
            'consent.accepted' => 'You must consent to the identity check before submitting.',
        ];
    }
}
