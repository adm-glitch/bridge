<?php

namespace App\Http\Requests\Api\Webhook;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class ConversationStatusChangedRequest extends FormRequest
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
            'event' => 'required|string|in:conversation_status_changed',
            'id' => 'required|integer|min:1',
            'account_id' => 'required|integer|min:1',
            'status' => 'required|string|in:open,resolved,pending,snoozed',
            'previous_status' => 'nullable|string|in:open,resolved,pending,snoozed',
            'changed_at' => 'required|date',
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
            'event.in' => 'Event must be conversation_status_changed',
            'id.required' => 'Conversation ID is required',
            'id.integer' => 'Conversation ID must be an integer',
            'account_id.required' => 'Account ID is required',
            'status.required' => 'Status is required',
            'status.in' => 'Status must be one of: open, resolved, pending, snoozed',
            'previous_status.in' => 'Previous status must be one of: open, resolved, pending, snoozed',
            'assignee.name.max' => 'Assignee name cannot exceed 255 characters',
            'assignee.email.email' => 'Assignee email must be valid',
            'team.name.max' => 'Team name cannot exceed 255 characters',
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
            'status' => 'conversation status',
            'previous_status' => 'previous status',
            'assignee.name' => 'assignee name',
            'assignee.email' => 'assignee email',
            'team.name' => 'team name'
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
            'webhook_type' => 'conversation_status_changed',
            'ip' => $this->ip(),
            'errors' => $errors->toArray(),
            'user_agent' => $this->userAgent(),
            'payload_preview' => $this->except(['additional_attributes'])
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

        // Sanitize team data
        if ($this->has('team.name')) {
            $this->merge([
                'team' => array_merge($this->input('team', []), [
                    'name' => strip_tags(trim($this->input('team.name')))
                ])
            ]);
        }

        // Sanitize labels
        if ($this->has('labels')) {
            $labels = array_map(function ($label) {
                return strip_tags(trim($label));
            }, $this->input('labels', []));
            $this->merge(['labels' => $labels]);
        }
    }

    /**
     * Get the rate limiting key for this request.
     */
    public function getRateLimitKey(): string
    {
        return 'webhook:conversation_status_changed:' . $this->ip();
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
