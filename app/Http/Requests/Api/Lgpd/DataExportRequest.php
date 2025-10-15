<?php

namespace App\Http\Requests\Api\Lgpd;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class DataExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'format' => 'nullable|string|in:json,xml,csv',
            'include_audit_logs' => 'nullable|boolean',
            'include_consent_records' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'format.in' => 'Format must be one of: json, xml, csv',
            'include_audit_logs.boolean' => 'Include audit logs must be true or false',
            'include_consent_records.boolean' => 'Include consent records must be true or false',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        Log::warning('Data export request validation failed', [
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
