<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class RefundPaymentRequest extends FormRequest
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
            'amount_minor' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
