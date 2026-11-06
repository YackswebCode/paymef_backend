<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkStoreInvoiceRequest extends FormRequest
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
            'invoices' => ['required', 'array', 'min:1', 'max:200'],
            'invoices.*.student_id' => ['required', 'integer', 'exists:users,id'],
            'invoices.*.amount_minor' => ['required', 'integer', 'min:1'],
            'invoices.*.currency' => ['nullable', 'string', 'size:3'],
            'invoices.*.status' => ['nullable', 'string', Rule::in(['draft', 'issued'])],
            'invoices.*.due_date' => ['nullable', 'date'],
            'invoices.*.description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
