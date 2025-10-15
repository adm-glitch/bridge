<?php

namespace App\Http\Requests\Api\Conversation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class ListMessagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'limit' => 'nullable|integer|min:1|max:200',
            'before_id' => 'nullable|integer|min:1',
            'after_id' => 'nullable|integer|min:1',
        ];
    }

    public function messages(): array
    {
        return [
            'limit.max' => 'Limit cannot exceed 200 messages per page',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        Log::warning('List messages validation failed', [
            'ip' => $this->ip(),
            'errors' => $validator->errors()->toArray(),
        ]);

        throw new HttpResponseException(response()->json([
            'success' => false,
            'error' => 'Validation failed',
            'error_code' => 'VALIDATION_ERROR',
            'details' => $validator->errors(),
            'timestamp' => now()->toIso8601String(),
        ], 422));
    }
}
