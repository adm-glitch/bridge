<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class VerifyChatwootSignature
{
    private const MAX_PAYLOAD_SIZE = 1048576; // 1MB
    private const TIMESTAMP_TOLERANCE = 300; // 5 minutes

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Check payload size (prevent DoS)
        $contentLength = $request->header('Content-Length');
        if ($contentLength && (int)$contentLength > self::MAX_PAYLOAD_SIZE) {
            Log::warning('Webhook payload too large', [
                'size' => $contentLength,
                'max_size' => self::MAX_PAYLOAD_SIZE,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Payload too large',
                'error_code' => 'PAYLOAD_TOO_LARGE',
                'max_size_bytes' => self::MAX_PAYLOAD_SIZE,
                'received_bytes' => $contentLength,
                'timestamp' => now()->toIso8601String()
            ], 413);
        }

        // 2. Extract headers
        $signature = $request->header('X-Chatwoot-Signature');
        $timestamp = $request->header('X-Chatwoot-Timestamp');

        if (!$signature || !$timestamp) {
            Log::warning('Missing webhook headers', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'has_signature' => !empty($signature),
                'has_timestamp' => !empty($timestamp)
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Missing signature or timestamp',
                'error_code' => 'MISSING_HEADERS',
                'required_headers' => ['X-Chatwoot-Signature', 'X-Chatwoot-Timestamp'],
                'timestamp' => now()->toIso8601String()
            ], 401);
        }

        // 3. Validate timestamp (prevent replay attacks)
        $currentTime = time();
        $webhookTime = (int)$timestamp;

        if (abs($currentTime - $webhookTime) > self::TIMESTAMP_TOLERANCE) {
            Log::warning('Webhook timestamp expired', [
                'timestamp' => $timestamp,
                'current_time' => $currentTime,
                'difference_seconds' => abs($currentTime - $webhookTime),
                'tolerance_seconds' => self::TIMESTAMP_TOLERANCE,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Timestamp expired',
                'error_code' => 'TIMESTAMP_EXPIRED',
                'details' => 'Webhook timestamp is older than 5 minutes',
                'timestamp' => now()->toIso8601String(),
                'webhook_timestamp' => $timestamp,
                'current_timestamp' => $currentTime
            ], 401);
        }

        // 4. Verify signature
        $payload = $request->getContent();
        $secret = config('services.chatwoot.webhook_secret');

        if (!$secret) {
            Log::error('Chatwoot webhook secret not configured', [
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Webhook configuration error',
                'error_code' => 'CONFIGURATION_ERROR',
                'timestamp' => now()->toIso8601String()
            ], 500);
        }

        $signedPayload = $timestamp . '.' . $payload;
        $expectedSignature = 'sha256=' . hash_hmac('sha256', $signedPayload, $secret);

        if (!hash_equals($expectedSignature, $signature)) {
            Log::warning('Invalid webhook signature', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'event' => $request->input('event'),
                'webhook_id' => $request->input('id'),
                'expected_signature' => substr($expectedSignature, 0, 10) . '...',
                'received_signature' => substr($signature, 0, 10) . '...'
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Invalid signature',
                'error_code' => 'INVALID_SIGNATURE',
                'details' => 'Signature verification failed',
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', 'req_' . uniqid())
            ], 403);
        }

        // 5. Check for duplicate processing (idempotency)
        $webhookId = $request->input('id');
        if ($webhookId) {
            $cacheKey = "webhook_processed:{$webhookId}";

            if (Cache::has($cacheKey)) {
                Log::info('Duplicate webhook ignored', [
                    'webhook_id' => $webhookId,
                    'ip' => $request->ip(),
                    'event' => $request->input('event')
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Already processed',
                    'webhook_id' => $webhookId,
                    'status' => 'duplicate',
                    'timestamp' => now()->toIso8601String()
                ], 200);
            }
        }

        // 6. Log successful signature verification
        Log::debug('Webhook signature verified', [
            'webhook_id' => $webhookId,
            'event' => $request->input('event'),
            'ip' => $request->ip(),
            'timestamp' => $timestamp
        ]);

        return $next($request);
    }
}
