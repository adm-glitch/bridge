<?php

namespace App\Http\Requests\Api\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class RefreshTokenRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'refresh_token' => 'sometimes|string|max:500',
            'device_info' => 'sometimes|string|max:500'
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'refresh_token.string' => 'Refresh token must be a string',
            'refresh_token.max' => 'Refresh token cannot exceed 500 characters',
            'device_info.max' => 'Device information cannot exceed 500 characters'
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'refresh_token' => 'refresh token',
            'device_info' => 'device information'
        ];
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors();

        // Log validation failure for security monitoring
        Log::warning('Token refresh validation failed', [
            'ip' => $this->ip(),
            'errors' => $errors->toArray(),
            'user_agent' => $this->userAgent()
        ]);

        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'error_code' => 'VALIDATION_ERROR',
                'details' => $errors,
                'timestamp' => now()->toIso8601String(),
                'request_id' => $this->header('X-Request-ID', 'req_' . uniqid())
            ], 422)
        );
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Remove any potential XSS attempts
        if ($this->has('device_info')) {
            $this->merge([
                'device_info' => strip_tags($this->input('device_info'))
            ]);
        }
    }

    /**
     * Get the rate limiting key for this request.
     */
    public function getRateLimitKey(): string
    {
        return 'auth:refresh:' . $this->ip();
    }

    /**
     * Get the maximum number of attempts allowed.
     */
    public function getMaxAttempts(): int
    {
        return 5;
    }

    /**
     * Get the number of minutes to throttle for.
     */
    public function getThrottleMinutes(): int
    {
        return 1;
    }
}
