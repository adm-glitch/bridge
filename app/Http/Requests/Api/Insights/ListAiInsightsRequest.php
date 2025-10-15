<?php

namespace App\Http\Requests\Api\Insights;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class ListAiInsightsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period' => 'nullable|string|in:7d,30d,90d,all',
            'include_history' => 'nullable|boolean',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        Log::warning('AI insights validation failed', [
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
