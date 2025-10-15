<?php

namespace App\Http\Requests\Api\Webhook;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class ConversationCreatedRequest extends FormRequest
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
            'event' => 'required|string|in:conversation_created',
            'id' => 'required|integer|min:1',
            'account_id' => 'required|integer|min:1',
            'inbox_id' => 'required|integer|min:1',
            'contact_id' => 'required|integer|min:1',
            'status' => 'required|string|in:open,resolved,pending,snoozed',
            'created_at' => 'required|date',
            'contact' => 'required|array',
            'contact.id' => 'required|integer|min:1',
            'contact.name' => 'required|string|max:255',
            'contact.email' => 'nullable|email|max:255',
            'contact.phone_number' => 'nullable|string|max:50',
            'contact.avatar_url' => 'nullable|url|max:500',
            'contact.custom_attributes' => 'nullable|array',
            'assignee' => 'nullable|array',
            'assignee.id' => 'nullable|integer|min:1',
            'assignee.name' => 'nullable|string|max:255',
            'assignee.email' => 'nullable|email|max:255',
            'team' => 'nullable|array',
            'team.id' => 'nullable|integer|min:1',
            'team.name' => 'nullable|string|max:255',
            'labels' => 'nullable|array',
            'labels.*' => 'string|max:100',
            'additional_attributes' => 'nullable|array'
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'event.required' => 'Event type is required',
            'event.in' => 'Event must be conversation_created',
            'id.required' => 'Conversation ID is required',
            'id.integer' => 'Conversation ID must be an integer',
            'account_id.required' => 'Account ID is required',
            'contact_id.required' => 'Contact ID is required',
            'status.required' => 'Status is required',
            'status.in' => 'Status must be one of: open, resolved, pending, snoozed',
            'contact.required' => 'Contact information is required',
            'contact.name.required' => 'Contact name is required',
            'contact.email.email' => 'Contact email must be valid',
            'contact.phone_number.max' => 'Phone number cannot exceed 50 characters',
            'assignee.email.email' => 'Assignee email must be valid',
            'labels.*.max' => 'Label cannot exceed 100 characters'
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'event' => 'event type',
            'id' => 'conversation ID',
            'account_id' => 'account ID',
            'contact_id' => 'contact ID',
            'status' => 'conversation status',
            'contact.name' => 'contact name',
            'contact.email' => 'contact email',
            'contact.phone_number' => 'contact phone number',
            'assignee.name' => 'assignee name',
            'assignee.email' => 'assignee email'
        ];
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors();

        // Log validation failure for security monitoring
        Log::warning('Webhook validation failed', [
            'webhook_type' => 'conversation_created',
            'ip' => $this->ip(),
            'errors' => $errors->toArray(),
            'user_agent' => $this->userAgent(),
            'payload_preview' => $this->except(['contact.custom_attributes', 'additional_attributes'])
        ]);

        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'error' => 'Webhook validation failed',
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
        // Sanitize string inputs
        if ($this->has('contact.name')) {
            $this->merge([
                'contact' => array_merge($this->input('contact', []), [
                    'name' => strip_tags(trim($this->input('contact.name')))
                ])
            ]);
        }

        if ($this->has('contact.email')) {
            $this->merge([
                'contact' => array_merge($this->input('contact', []), [
                    'email' => strtolower(trim($this->input('contact.email')))
                ])
            ]);
        }

        if ($this->has('contact.phone_number')) {
            $this->merge([
                'contact' => array_merge($this->input('contact', []), [
                    'phone_number' => preg_replace('/[^0-9+\-\s()]/', '', $this->input('contact.phone_number'))
                ])
            ]);
        }

        // Sanitize assignee data
        if ($this->has('assignee.name')) {
            $this->merge([
                'assignee' => array_merge($this->input('assignee', []), [
                    'name' => strip_tags(trim($this->input('assignee.name')))
                ])
            ]);
        }

        if ($this->has('assignee.email')) {
            $this->merge([
                'assignee' => array_merge($this->input('assignee', []), [
                    'email' => strtolower(trim($this->input('assignee.email')))
                ])
            ]);
        }
    }

    /**
     * Get the rate limiting key for this request.
     */
    public function getRateLimitKey(): string
    {
        return 'webhook:conversation_created:' . $this->ip();
    }

    /**
     * Get the maximum number of attempts allowed.
     */
    public function getMaxAttempts(): int
    {
        return 100; // 100 requests per minute
    }

    /**
     * Get the number of minutes to throttle for.
     */
    public function getThrottleMinutes(): int
    {
        return 1;
    }
}
