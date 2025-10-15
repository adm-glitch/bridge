<?php

namespace App\Http\Requests\Api\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class LoginRequest extends FormRequest
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
            'email' => [
                'required',
                'string',
                'email:rfc,dns',
                'max:255',
                'regex:/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/'
            ],
            'password' => [
                'required',
                'string',
                'min:8',
                'max:128',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/'
            ],
            'remember_me' => 'sometimes|boolean',
            'device_info' => 'sometimes|string|max:500'
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Email address is required',
            'email.email' => 'Please provide a valid email address',
            'email.regex' => 'Email format is invalid',
            'password.required' => 'Password is required',
            'password.min' => 'Password must be at least 8 characters long',
            'password.max' => 'Password cannot exceed 128 characters',
            'password.regex' => 'Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character',
            'device_info.max' => 'Device information cannot exceed 500 characters'
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'email' => 'email address',
            'password' => 'password',
            'remember_me' => 'remember me option',
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
        \Log::warning('Login validation failed', [
            'ip' => $this->ip(),
            'email' => $this->input('email'),
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
        // Sanitize email
        if ($this->has('email')) {
            $this->merge([
                'email' => strtolower(trim($this->input('email')))
            ]);
        }

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
        return 'auth:login:' . $this->ip();
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
