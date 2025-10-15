<?php

namespace App\Http\Requests\Api\Health;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class HealthCheckRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Health checks are public
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'include_metrics' => 'sometimes|boolean',
            'include_queues' => 'sometimes|boolean',
            'timeout' => 'sometimes|integer|min:1|max:30'
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'include_metrics.boolean' => 'Include metrics must be a boolean value',
            'include_queues.boolean' => 'Include queues must be a boolean value',
            'timeout.integer' => 'Timeout must be an integer',
            'timeout.min' => 'Timeout must be at least 1 second',
            'timeout.max' => 'Timeout must not exceed 30 seconds'
        ];
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors();

        Log::warning('Health check request validation failed', [
            'errors' => $errors->toArray(),
            'ip' => $this->ip(),
            'user_agent' => $this->userAgent()
        ]);

        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'error_code' => 'VALIDATION_ERROR',
                'details' => $errors->toArray(),
                'timestamp' => now()->toIso8601String()
            ], 422)
        );
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // XSS prevention
        $this->merge([
            'include_metrics' => filter_var($this->input('include_metrics'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            'include_queues' => filter_var($this->input('include_queues'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            'timeout' => filter_var($this->input('timeout'), FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 30]
            ])
        ]);
    }
}
