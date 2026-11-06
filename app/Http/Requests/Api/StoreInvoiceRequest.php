<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
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
            'student_id' => ['required', 'integer', 'exists:users,id'],
            'description' => ['nullable', 'string', 'max:255'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['nullable', 'string', 'size:3'],
            'status' => ['nullable', 'string', Rule::in(['draft', 'issued'])],
            'due_date' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:50', 'unique:invoices,reference'],
        ];
    }
}
