<?php

namespace App\Http\Requests\Api\Export;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class BulkExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contact_ids' => 'required|array|min:1|max:100',
            'contact_ids.*' => 'required|integer|min:1',
            'format' => 'required|string|in:json,xml,csv,zip',
            'include_audit_logs' => 'nullable|boolean',
            'include_consent_records' => 'nullable|boolean',
            'include_conversations' => 'nullable|boolean',
            'include_messages' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'contact_ids.required' => 'Contact IDs are required',
            'contact_ids.array' => 'Contact IDs must be an array',
            'contact_ids.min' => 'At least one contact ID is required',
            'contact_ids.max' => 'Maximum 100 contacts can be exported at once',
            'contact_ids.*.required' => 'Each contact ID is required',
            'contact_ids.*.integer' => 'Each contact ID must be an integer',
            'contact_ids.*.min' => 'Each contact ID must be greater than 0',
            'format.required' => 'Export format is required',
            'format.in' => 'Format must be one of: json, xml, csv, zip',
            'include_audit_logs.boolean' => 'Include audit logs must be true or false',
            'include_consent_records.boolean' => 'Include consent records must be true or false',
            'include_conversations.boolean' => 'Include conversations must be true or false',
            'include_messages.boolean' => 'Include messages must be true or false',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        Log::warning('Bulk export request validation failed', [
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
