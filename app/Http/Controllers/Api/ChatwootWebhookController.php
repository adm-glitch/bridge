<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Webhook\ConversationCreatedRequest;
use App\Http\Requests\Api\Webhook\MessageCreatedRequest;
use App\Http\Requests\Api\Webhook\ConversationStatusChangedRequest;
use App\Services\WebhookService;
use App\Services\AuditService;
use App\Jobs\ProcessConversationCreated;
use App\Jobs\ProcessMessageCreated;
use App\Jobs\ProcessConversationStatusChanged;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class ChatwootWebhookController extends Controller
{
    private WebhookService $webhookService;
    private AuditService $auditService;

    public function __construct(WebhookService $webhookService, AuditService $auditService)
    {
        $this->webhookService = $webhookService;
        $this->auditService = $auditService;
    }

    /**
     * Handle conversation created webhook
     * 
     * @param ConversationCreatedRequest $request
     * @return JsonResponse
     */
    public function conversationCreated(ConversationCreatedRequest $request): JsonResponse
    {
        $webhookId = $request->input('id');
        $payload = $request->validated();
        $ip = $request->ip();
        $userAgent = $request->userAgent();

        try {
            // Log webhook receipt
            Log::info('Conversation created webhook received', [
                'webhook_id' => $webhookId,
                'conversation_id' => $payload['id'],
                'contact_id' => $payload['contact']['id'] ?? null,
                'ip' => $ip
            ]);

            // Check for duplicate processing (idempotency)
            $cacheKey = "webhook_processed:{$webhookId}";
            if (Cache::has($cacheKey)) {
                Log::info('Duplicate webhook ignored', [
                    'webhook_id' => $webhookId,
                    'ip' => $ip
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Already processed',
                    'webhook_id' => $webhookId,
                    'status' => 'duplicate'
                ], 200);
            }

            // Dispatch to queue immediately (non-blocking)
            ProcessConversationCreated::dispatch($webhookId, $payload, $ip, $userAgent)
                ->onQueue('webhooks-high') // High priority queue
                ->delay(now()->addSeconds(2)); // Small delay to batch

            // Mark as processed (24 hour TTL)
            Cache::put($cacheKey, true, 86400);

            // Log security event
            $this->auditService->logSecurityEvent('webhook_received', [
                'webhook_type' => 'conversation_created',
                'webhook_id' => $webhookId,
                'ip' => $ip,
                'user_agent' => $userAgent
            ]);

            // Return 200 immediately (webhook acknowledged)
            return response()->json([
                'success' => true,
                'webhook_id' => $webhookId,
                'queued_at' => now()->toIso8601String(),
                'processing_status' => 'queued',
                'estimated_processing_time_seconds' => 5,
                'response_time_ms' => round((microtime(true) - LARAVEL_START) * 1000, 2)
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to process conversation_created webhook', [
                'webhook_id' => $webhookId,
                'ip' => $ip,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Webhook processing failed',
                'error_code' => 'WEBHOOK_PROCESSING_ERROR',
                'webhook_id' => $webhookId,
                'timestamp' => now()->toIso8601String()
            ], 500);
        }
    }

    /**
     * Handle message created webhook
     * 
     * @param MessageCreatedRequest $request
     * @return JsonResponse
     */
    public function messageCreated(MessageCreatedRequest $request): JsonResponse
    {
        $webhookId = $request->input('id');
        $payload = $request->validated();
        $ip = $request->ip();
        $userAgent = $request->userAgent();

        try {
            // Log webhook receipt
            Log::info('Message created webhook received', [
                'webhook_id' => $webhookId,
                'message_id' => $payload['id'],
                'conversation_id' => $payload['conversation_id'],
                'ip' => $ip
            ]);

            // Check for duplicate processing
            $cacheKey = "webhook_processed:{$webhookId}";
            if (Cache::has($cacheKey)) {
                Log::info('Duplicate webhook ignored', [
                    'webhook_id' => $webhookId,
                    'ip' => $ip
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Already processed',
                    'webhook_id' => $webhookId,
                    'status' => 'duplicate'
                ], 200);
            }

            // Dispatch to queue
            ProcessMessageCreated::dispatch($webhookId, $payload, $ip, $userAgent)
                ->onQueue('webhooks-normal')
                ->delay(now()->addSeconds(5));

            // Mark as processed
            Cache::put($cacheKey, true, 86400);

            // Log security event
            $this->auditService->logSecurityEvent('webhook_received', [
                'webhook_type' => 'message_created',
                'webhook_id' => $webhookId,
                'ip' => $ip,
                'user_agent' => $userAgent
            ]);

            return response()->json([
                'success' => true,
                'webhook_id' => $webhookId,
                'queued_at' => now()->toIso8601String(),
                'processing_status' => 'queued',
                'estimated_processing_time_seconds' => 3,
                'response_time_ms' => round((microtime(true) - LARAVEL_START) * 1000, 2)
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to process message_created webhook', [
                'webhook_id' => $webhookId,
                'ip' => $ip,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Webhook processing failed',
                'error_code' => 'WEBHOOK_PROCESSING_ERROR',
                'webhook_id' => $webhookId,
                'timestamp' => now()->toIso8601String()
            ], 500);
        }
    }

    /**
     * Handle conversation status changed webhook
     * 
     * @param ConversationStatusChangedRequest $request
     * @return JsonResponse
     */
    public function statusChanged(ConversationStatusChangedRequest $request): JsonResponse
    {
        $webhookId = $request->input('id');
        $payload = $request->validated();
        $ip = $request->ip();
        $userAgent = $request->userAgent();

        try {
            // Log webhook receipt
            Log::info('Conversation status changed webhook received', [
                'webhook_id' => $webhookId,
                'conversation_id' => $payload['id'],
                'status' => $payload['status'],
                'previous_status' => $payload['previous_status'] ?? null,
                'ip' => $ip
            ]);

            // Check for duplicate processing
            $cacheKey = "webhook_processed:{$webhookId}";
            if (Cache::has($cacheKey)) {
                Log::info('Duplicate webhook ignored', [
                    'webhook_id' => $webhookId,
                    'ip' => $ip
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Already processed',
                    'webhook_id' => $webhookId,
                    'status' => 'duplicate'
                ], 200);
            }

            // Dispatch to queue
            ProcessConversationStatusChanged::dispatch($webhookId, $payload, $ip, $userAgent)
                ->onQueue('webhooks-high')
                ->delay(now()->addSeconds(1));

            // Mark as processed
            Cache::put($cacheKey, true, 86400);

            // Log security event
            $this->auditService->logSecurityEvent('webhook_received', [
                'webhook_type' => 'conversation_status_changed',
                'webhook_id' => $webhookId,
                'ip' => $ip,
                'user_agent' => $userAgent
            ]);

            return response()->json([
                'success' => true,
                'webhook_id' => $webhookId,
                'queued_at' => now()->toIso8601String(),
                'processing_status' => 'queued',
                'estimated_processing_time_seconds' => 2,
                'response_time_ms' => round((microtime(true) - LARAVEL_START) * 1000, 2)
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to process conversation_status_changed webhook', [
                'webhook_id' => $webhookId,
                'ip' => $ip,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Webhook processing failed',
                'error_code' => 'WEBHOOK_PROCESSING_ERROR',
                'webhook_id' => $webhookId,
                'timestamp' => now()->toIso8601String()
            ], 500);
        }
    }

    /**
     * Handle webhook test endpoint
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function test(Request $request): JsonResponse
    {
        $ip = $request->ip();
        $userAgent = $request->userAgent();

        Log::info('Webhook test endpoint accessed', [
            'ip' => $ip,
            'user_agent' => $userAgent,
            'headers' => $request->headers->all()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Webhook endpoint is working',
            'timestamp' => now()->toIso8601String(),
            'server_time' => now()->toIso8601String(),
            'response_time_ms' => round((microtime(true) - LARAVEL_START) * 1000, 2)
        ], 200);
    }

    /**
     * Get webhook processing status
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function status(Request $request): JsonResponse
    {
        $webhookId = $request->query('webhook_id');

        if (!$webhookId) {
            return response()->json([
                'success' => false,
                'error' => 'Webhook ID required',
                'error_code' => 'WEBHOOK_ID_REQUIRED'
            ], 400);
        }

        try {
            $cacheKey = "webhook_processed:{$webhookId}";
            $isProcessed = Cache::has($cacheKey);

            return response()->json([
                'success' => true,
                'webhook_id' => $webhookId,
                'processed' => $isProcessed,
                'status' => $isProcessed ? 'completed' : 'pending',
                'timestamp' => now()->toIso8601String()
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to check webhook status', [
                'webhook_id' => $webhookId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Status check failed',
                'error_code' => 'STATUS_CHECK_ERROR',
                'timestamp' => now()->toIso8601String()
            ], 500);
        }
    }
}
