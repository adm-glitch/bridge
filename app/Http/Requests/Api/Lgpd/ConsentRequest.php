<?php

namespace App\Http\Requests\Api\Lgpd;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class ConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contact_id' => 'required|integer|min:1',
            'consent_type' => 'required|string|in:data_processing,marketing,health_data',
            'consent_granted' => 'required|boolean',
            'ip_address' => 'required|ip',
            'user_agent' => 'required|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'contact_id.required' => 'Contact ID is required',
            'contact_id.integer' => 'Contact ID must be a valid integer',
            'consent_type.required' => 'Consent type is required',
            'consent_type.in' => 'Consent type must be one of: data_processing, marketing, health_data',
            'consent_granted.required' => 'Consent status is required',
            'consent_granted.boolean' => 'Consent status must be true or false',
            'ip_address.required' => 'IP address is required for audit trail',
            'ip_address.ip' => 'IP address must be a valid IP address',
            'user_agent.required' => 'User agent is required for audit trail',
            'user_agent.max' => 'User agent cannot exceed 500 characters',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        Log::warning('Consent request validation failed', [
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
