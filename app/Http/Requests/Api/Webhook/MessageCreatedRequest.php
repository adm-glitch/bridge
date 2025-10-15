<?php

namespace App\Http\Requests\Api\Webhook;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class MessageCreatedRequest extends FormRequest
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
            'event' => 'required|string|in:message_created',
            'id' => 'required|integer|min:1',
            'conversation_id' => 'required|integer|min:1',
            'account_id' => 'required|integer|min:1',
            'content' => 'required|string|max:10000',
            'message_type' => 'required|string|in:incoming,outgoing,activity',
            'content_type' => 'required|string|in:text,image,video,audio,file,location,fallback',
            'created_at' => 'required|date',
            'sender' => 'required|array',
            'sender.id' => 'required|integer|min:1',
            'sender.name' => 'required|string|max:255',
            'sender.type' => 'required|string|in:contact,agent,bot',
            'sender.avatar_url' => 'nullable|url|max:500',
            'sender.email' => 'nullable|email|max:255',
            'attachments' => 'nullable|array',
            'attachments.*.id' => 'nullable|integer|min:1',
            'attachments.*.file_type' => 'nullable|string|max:50',
            'attachments.*.file_size' => 'nullable|integer|min:0',
            'attachments.*.file_url' => 'nullable|url|max:500',
            'attachments.*.file_name' => 'nullable|string|max:255',
            'private' => 'nullable|boolean',
            'read' => 'nullable|boolean',
            'read_at' => 'nullable|date',
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
            'event.in' => 'Event must be message_created',
            'id.required' => 'Message ID is required',
            'conversation_id.required' => 'Conversation ID is required',
            'content.required' => 'Message content is required',
            'content.max' => 'Message content cannot exceed 10000 characters',
            'message_type.required' => 'Message type is required',
            'message_type.in' => 'Message type must be one of: incoming, outgoing, activity',
            'content_type.required' => 'Content type is required',
            'content_type.in' => 'Content type must be one of: text, image, video, audio, file, location, fallback',
            'sender.required' => 'Sender information is required',
            'sender.name.required' => 'Sender name is required',
            'sender.type.required' => 'Sender type is required',
            'sender.type.in' => 'Sender type must be one of: contact, agent, bot',
            'sender.email.email' => 'Sender email must be valid',
            'attachments.*.file_type.max' => 'File type cannot exceed 50 characters',
            'attachments.*.file_name.max' => 'File name cannot exceed 255 characters'
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'event' => 'event type',
            'id' => 'message ID',
            'conversation_id' => 'conversation ID',
            'content' => 'message content',
            'message_type' => 'message type',
            'content_type' => 'content type',
            'sender.name' => 'sender name',
            'sender.type' => 'sender type',
            'sender.email' => 'sender email'
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
            'webhook_type' => 'message_created',
            'ip' => $this->ip(),
            'errors' => $errors->toArray(),
            'user_agent' => $this->userAgent(),
            'payload_preview' => $this->except(['content', 'additional_attributes'])
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
        // Sanitize content
        if ($this->has('content')) {
            $this->merge([
                'content' => strip_tags($this->input('content'))
            ]);
        }

        // Sanitize sender data
        if ($this->has('sender.name')) {
            $this->merge([
                'sender' => array_merge($this->input('sender', []), [
                    'name' => strip_tags(trim($this->input('sender.name')))
                ])
            ]);
        }

        if ($this->has('sender.email')) {
            $this->merge([
                'sender' => array_merge($this->input('sender', []), [
                    'email' => strtolower(trim($this->input('sender.email')))
                ])
            ]);
        }

        // Sanitize attachment file names
        if ($this->has('attachments')) {
            $attachments = $this->input('attachments', []);
            foreach ($attachments as $index => $attachment) {
                if (isset($attachment['file_name'])) {
                    $attachments[$index]['file_name'] = basename($attachment['file_name']);
                }
            }
            $this->merge(['attachments' => $attachments]);
        }
    }

    /**
     * Get the rate limiting key for this request.
     */
    public function getRateLimitKey(): string
    {
        return 'webhook:message_created:' . $this->ip();
    }

    /**
     * Get the maximum number of attempts allowed.
     */
    public function getMaxAttempts(): int
    {
        return 200; // 200 requests per minute
    }

    /**
     * Get the number of minutes to throttle for.
     */
    public function getThrottleMinutes(): int
    {
        return 1;
    }
}
