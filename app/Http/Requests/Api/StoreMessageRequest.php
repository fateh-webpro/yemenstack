<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recipient' => ['required', 'string', 'max:30'],
            'body' => ['required', 'string', 'max:5000'],
            'scheduled_at' => ['nullable', 'date', 'after_or_equal:now'],
            'payload' => ['nullable', 'array'],
        ];
    }
}