<?php

namespace App\Http\Requests\Api\Lgpd;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class DataDeletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'confirmation' => 'required|string|in:DELETE_ALL_DATA',
            'reason' => 'required|string|min:10|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'confirmation.required' => 'Confirmation is required',
            'confirmation.in' => 'Confirmation must be exactly "DELETE_ALL_DATA"',
            'reason.required' => 'Reason for deletion is required',
            'reason.min' => 'Reason must be at least 10 characters',
            'reason.max' => 'Reason cannot exceed 500 characters',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        Log::warning('Data deletion request validation failed', [
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
