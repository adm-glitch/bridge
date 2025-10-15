<?php

namespace App\Http\Requests\Api\Conversation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class ListConversationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => 'nullable|string|in:open,resolved,pending,snoozed',
            'limit' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
            'sort' => 'nullable|string|in:updated_at,created_at',
            'order' => 'nullable|string|in:asc,desc',
        ];
    }

    public function messages(): array
    {
        return [
            'status.in' => 'Status must be one of: open, resolved, pending, snoozed',
            'limit.max' => 'Limit cannot exceed 100 items per page',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        Log::warning('List conversations validation failed', [
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
