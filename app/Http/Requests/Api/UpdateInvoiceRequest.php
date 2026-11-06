<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceRequest extends FormRequest
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
            'description' => ['nullable', 'string', 'max:255'],
            'due_date' => ['nullable', 'date'],
            'amount_minor' => ['sometimes', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ];
    }
}
