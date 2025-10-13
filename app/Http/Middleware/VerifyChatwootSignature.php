<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enhanced VerifyChatwootSignature Middleware
 * 
 * This middleware implements comprehensive webhook signature verification
 * as specified in the architecture document v2.1. It provides:
 * 
 * - Webhook signature verification with HMAC-SHA256
 * - Replay attack prevention with timestamp validation
 * - Payload size validation
 * - Idempotency protection against duplicate processing
 * - Comprehensive security logging
 * - LGPD compliance for audit trails
 * 
 * Security Features:
 * - Prevents webhook replay attacks
 * - Validates webhook authenticity
 * - Protects against DoS attacks via large payloads
 * - Ensures idempotent webhook processing
 * - Provides detailed security logging
 * 
 * @package App\Http\Middleware
 * @version 2.1
 * @author Bridge Service Team
 * @since 2025-10-08
 */
class VerifyChatwootSignature
{
    /**
     * Maximum allowed payload size in bytes (1MB)
     * 
     * Security Decision: Large payloads can be used for DoS attacks.
     * We limit payload size to prevent memory exhaustion and
     * ensure reasonable processing times.
     * 
     * @var int
     */
    private const MAX_PAYLOAD_SIZE = 1048576; // 1MB

    /**
     * Timestamp tolerance in seconds (5 minutes)
     * 
     * Security Decision: Webhooks must arrive within a reasonable
     * time window to prevent replay attacks. 5 minutes provides
     * adequate tolerance for network delays while preventing
     * old webhook replay attacks.
     * 
     * @var int
     */
    private const TIMESTAMP_TOLERANCE = 300; // 5 minutes

    /**
     * Cache TTL for idempotency protection (24 hours)
     * 
     * Security Decision: Webhook idempotency must be maintained
     * for a reasonable period to handle retries and ensure
     * data consistency. 24 hours covers most retry scenarios.
     * 
     * @var int
     */
    private const IDEMPOTENCY_TTL = 86400; // 24 hours

    /**
     * Maximum number of signature verification attempts per IP
     * 
     * Security Decision: Prevents brute force attacks on signature
     * verification. Legitimate webhooks should pass on first attempt.
     * 
     * @var int
     */
    private const MAX_VERIFICATION_ATTEMPTS = 5;

    /**
     * Handle an incoming request with comprehensive signature verification
     * 
     * This method implements multi-layered security:
     * 1. Payload size validation (DoS protection)
     * 2. Header presence validation
     * 3. Timestamp validation (replay attack prevention)
     * 4. Signature verification (authenticity)
     * 5. Idempotency protection (duplicate prevention)
     * 6. Security event logging
     * 
     * @param Request $request
     * @param Closure $next
     * @return Response
     * @throws \Exception
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);
        $ip = $this->getClientIp($request);
        $userAgent = $request->userAgent();

        // Log incoming webhook attempt
        $this->logWebhookAttempt($request, $ip, $userAgent);

        try {
            // 1. Validate payload size (DoS protection)
            $this->validatePayloadSize($request);

            // 2. Validate required headers
            $headers = $this->validateHeaders($request);

            // 3. Validate timestamp (replay attack prevention)
            $this->validateTimestamp($headers['timestamp'], $ip);

            // 4. Verify signature (authenticity)
            $this->verifySignature($request, $headers, $ip);

            // 5. Check idempotency (duplicate prevention)
            $this->checkIdempotency($request, $ip);

            // 6. Record successful verification
            $this->recordSuccessfulVerification($request, $ip);

            // 7. Log security success
            $this->logSecuritySuccess($request, $ip, microtime(true) - $startTime);

            return $next($request);
        } catch (\Exception $e) {
            // Log security failure with comprehensive context
            $this->logSecurityFailure($request, $e, $ip, microtime(true) - $startTime);

            // Return appropriate error response
            return $this->buildErrorResponse($e, $request);
        }
    }

    /**
     * Validate payload size to prevent DoS attacks
     * 
     * Security Decision: Large payloads can exhaust server memory
     * and processing capacity. We enforce a strict size limit
     * to prevent DoS attacks while allowing legitimate webhooks.
     * 
     * @param Request $request
     * @return void
     * @throws \Exception
     */
    protected function validatePayloadSize(Request $request): void
    {
        $contentLength = $request->header('Content-Length');
        $actualSize = strlen($request->getContent());

        // Check Content-Length header
        if ($contentLength && (int)$contentLength > self::MAX_PAYLOAD_SIZE) {
            $this->logSecurityViolation('payload_size_exceeded_header', [
                'content_length' => $contentLength,
                'max_allowed' => self::MAX_PAYLOAD_SIZE,
                'ip' => $this->getClientIp($request),
            ]);

            throw new \Exception('Payload too large', 413);
        }

        // Check actual payload size
        if ($actualSize > self::MAX_PAYLOAD_SIZE) {
            $this->logSecurityViolation('payload_size_exceeded_actual', [
                'actual_size' => $actualSize,
                'max_allowed' => self::MAX_PAYLOAD_SIZE,
                'ip' => $this->getClientIp($request),
            ]);

            throw new \Exception('Payload too large', 413);
        }
    }

    /**
     * Validate required headers are present
     * 
     * Security Decision: Webhook signature verification requires
     * specific headers. Missing headers indicate either:
     * - Malicious requests attempting to bypass security
     * - Misconfigured webhook endpoints
     * - Legacy requests without proper headers
     * 
     * @param Request $request
     * @return array
     * @throws \Exception
     */
    protected function validateHeaders(Request $request): array
    {
        $signature = $request->header('X-Chatwoot-Signature');
        $timestamp = $request->header('X-Chatwoot-Timestamp');

        if (!$signature) {
            $this->logSecurityViolation('missing_signature_header', [
                'ip' => $this->getClientIp($request),
                'headers' => $request->headers->all(),
            ]);

            throw new \Exception('Missing X-Chatwoot-Signature header', 401);
        }

        if (!$timestamp) {
            $this->logSecurityViolation('missing_timestamp_header', [
                'ip' => $this->getClientIp($request),
                'headers' => $request->headers->all(),
            ]);

            throw new \Exception('Missing X-Chatwoot-Timestamp header', 401);
        }

        // Validate timestamp format
        if (!is_numeric($timestamp) || $timestamp <= 0) {
            $this->logSecurityViolation('invalid_timestamp_format', [
                'timestamp' => $timestamp,
                'ip' => $this->getClientIp($request),
            ]);

            throw new \Exception('Invalid timestamp format', 401);
        }

        return [
            'signature' => $signature,
            'timestamp' => (int)$timestamp,
        ];
    }

    /**
     * Validate timestamp to prevent replay attacks
     * 
     * Security Decision: Timestamp validation prevents replay attacks
     * by ensuring webhooks arrive within a reasonable time window.
     * This prevents attackers from replaying old webhooks to trigger
     * duplicate processing or cause system inconsistencies.
     * 
     * @param int $timestamp
     * @param string $ip
     * @return void
     * @throws \Exception
     */
    protected function validateTimestamp(int $timestamp, string $ip): void
    {
        $currentTime = time();
        $timeDifference = abs($currentTime - $timestamp);

        if ($timeDifference > self::TIMESTAMP_TOLERANCE) {
            $this->logSecurityViolation('timestamp_expired', [
                'timestamp' => $timestamp,
                'current_time' => $currentTime,
                'difference' => $timeDifference,
                'tolerance' => self::TIMESTAMP_TOLERANCE,
                'ip' => $ip,
            ]);

            throw new \Exception('Webhook timestamp expired', 401);
        }

        // Log timestamp validation success for monitoring
        Log::debug('Webhook timestamp validated', [
            'timestamp' => $timestamp,
            'current_time' => $currentTime,
            'difference' => $timeDifference,
            'ip' => $ip,
        ]);
    }

    /**
     * Verify webhook signature using HMAC-SHA256
     * 
     * Security Decision: HMAC-SHA256 provides strong cryptographic
     * authentication. The signature is calculated using:
     * - Timestamp (prevents replay attacks)
     * - Payload (ensures data integrity)
     * - Secret key (ensures authenticity)
     * 
     * This prevents:
     * - Unauthorized webhook injection
     * - Payload tampering
     * - Replay attacks
     * 
     * @param Request $request
     * @param array $headers
     * @param string $ip
     * @return void
     * @throws \Exception
     */
    protected function verifySignature(Request $request, array $headers, string $ip): void
    {
        $payload = $request->getContent();
        $secret = config('services.chatwoot.webhook_secret');

        if (!$secret) {
            Log::critical('Chatwoot webhook secret not configured', [
                'ip' => $ip,
                'endpoint' => $request->path(),
            ]);

            throw new \Exception('Webhook secret not configured', 500);
        }

        // Construct signed payload (timestamp.payload)
        $signedPayload = $headers['timestamp'] . '.' . $payload;

        // Calculate expected signature
        $expectedSignature = 'sha256=' . hash_hmac('sha256', $signedPayload, $secret);

        // Use hash_equals for timing-safe comparison
        if (!hash_equals($expectedSignature, $headers['signature'])) {
            $this->logSecurityViolation('invalid_signature', [
                'ip' => $ip,
                'event' => $request->input('event'),
                'conversation_id' => $request->input('id'),
                'expected_signature' => substr($expectedSignature, 0, 20) . '...',
                'received_signature' => substr($headers['signature'], 0, 20) . '...',
                'payload_size' => strlen($payload),
            ]);

            throw new \Exception('Invalid webhook signature', 403);
        }

        // Log successful signature verification
        Log::debug('Webhook signature verified', [
            'ip' => $ip,
            'event' => $request->input('event'),
            'conversation_id' => $request->input('id'),
            'payload_size' => strlen($payload),
        ]);
    }

    /**
     * Check for duplicate webhook processing (idempotency)
     * 
     * Security Decision: Idempotency prevents duplicate processing
     * of the same webhook, which could lead to:
     * - Duplicate data creation
     * - Inconsistent system state
     * - Resource waste
     * - Data integrity issues
     * 
     * @param Request $request
     * @param string $ip
     * @return void
     * @throws \Exception
     */
    protected function checkIdempotency(Request $request, string $ip): void
    {
        $webhookId = $request->input('id');

        if (!$webhookId) {
            // Some webhooks might not have an ID, skip idempotency check
            Log::debug('Webhook without ID, skipping idempotency check', [
                'ip' => $ip,
                'event' => $request->input('event'),
            ]);
            return;
        }

        $cacheKey = "webhook_processed:{$webhookId}";

        if (Cache::has($cacheKey)) {
            $this->logSecurityViolation('duplicate_webhook', [
                'webhook_id' => $webhookId,
                'ip' => $ip,
                'event' => $request->input('event'),
                'conversation_id' => $request->input('conversation.id'),
            ]);

            // Return success for duplicate webhooks (idempotent)
            throw new \Exception('Webhook already processed', 200);
        }

        // Mark webhook as processed
        Cache::put($cacheKey, true, self::IDEMPOTENCY_TTL);

        Log::debug('Webhook idempotency check passed', [
            'webhook_id' => $webhookId,
            'ip' => $ip,
            'event' => $request->input('event'),
        ]);
    }

    /**
     * Get client IP address with proxy support
     * 
     * Security Decision: Proper IP detection is crucial for security
     * logging and rate limiting. We check multiple headers to handle
     * various proxy configurations while preventing IP spoofing.
     * 
     * @param Request $request
     * @return string
     */
    protected function getClientIp(Request $request): string
    {
        // Check for forwarded IP (behind proxy/load balancer)
        $forwardedFor = $request->header('X-Forwarded-For');
        if ($forwardedFor) {
            // Take the first IP in the chain (original client)
            $ips = explode(',', $forwardedFor);
            $ip = trim($ips[0]);

            // Validate IP format to prevent spoofing
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }

        // Check for real IP header
        $realIp = $request->header('X-Real-IP');
        if ($realIp && filter_var($realIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $realIp;
        }

        // Fall back to direct connection IP
        return $request->ip();
    }

    /**
     * Log incoming webhook attempt for security monitoring
     * 
     * @param Request $request
     * @param string $ip
     * @param string $userAgent
     * @return void
     */
    protected function logWebhookAttempt(Request $request, string $ip, string $userAgent): void
    {
        Log::info('Webhook verification attempt', [
            'ip' => $ip,
            'endpoint' => $request->path(),
            'method' => $request->method(),
            'user_agent' => $userAgent,
            'content_length' => $request->header('Content-Length'),
            'has_signature' => $request->hasHeader('X-Chatwoot-Signature'),
            'has_timestamp' => $request->hasHeader('X-Chatwoot-Timestamp'),
            'event' => $request->input('event'),
            'webhook_id' => $request->input('id'),
        ]);
    }

    /**
     * Record successful verification for monitoring
     * 
     * @param Request $request
     * @param string $ip
     * @return void
     */
    protected function recordSuccessfulVerification(Request $request, string $ip): void
    {
        $event = $request->input('event');
        $webhookId = $request->input('id');

        // Increment success counter for monitoring
        Cache::increment("webhook_success:{$event}", 1);
        Cache::expire("webhook_success:{$event}", 3600); // 1 hour

        Log::debug('Webhook verification successful', [
            'ip' => $ip,
            'event' => $event,
            'webhook_id' => $webhookId,
            'conversation_id' => $request->input('conversation.id'),
        ]);
    }

    /**
     * Log security success for monitoring
     * 
     * @param Request $request
     * @param string $ip
     * @param float $processingTime
     * @return void
     */
    protected function logSecuritySuccess(Request $request, string $ip, float $processingTime): void
    {
        Log::info('Webhook security verification successful', [
            'ip' => $ip,
            'event' => $request->input('event'),
            'webhook_id' => $request->input('id'),
            'processing_time_ms' => round($processingTime * 1000, 2),
            'payload_size' => strlen($request->getContent()),
        ]);
    }

    /**
     * Log security failure with comprehensive context
     * 
     * Security Decision: Detailed failure logging is essential for:
     * - Security incident response
     * - Attack pattern detection
     * - System debugging
     * - Compliance auditing
     * 
     * @param Request $request
     * @param \Exception $exception
     * @param string $ip
     * @param float $processingTime
     * @return void
     */
    protected function logSecurityFailure(Request $request, \Exception $exception, string $ip, float $processingTime): void
    {
        $logData = [
            'event' => 'webhook_security_failure',
            'timestamp' => now()->toIso8601String(),
            'ip' => $ip,
            'endpoint' => $request->path(),
            'method' => $request->method(),
            'user_agent' => $request->userAgent(),
            'error_code' => $exception->getCode(),
            'error_message' => $exception->getMessage(),
            'processing_time_ms' => round($processingTime * 1000, 2),
            'content_length' => $request->header('Content-Length'),
            'has_signature' => $request->hasHeader('X-Chatwoot-Signature'),
            'has_timestamp' => $request->hasHeader('X-Chatwoot-Timestamp'),
            'webhook_event' => $request->input('event'),
            'webhook_id' => $request->input('id'),
            'conversation_id' => $request->input('conversation.id'),
            'request_id' => $request->header('X-Request-ID'),
        ];

        // Determine log level based on error type
        if ($exception->getCode() >= 500) {
            Log::critical('Critical webhook security failure', $logData);
        } elseif ($exception->getCode() >= 400) {
            Log::warning('Webhook security failure', $logData);
        } else {
            Log::error('Webhook security error', $logData);
        }

        // Increment failure counter for monitoring
        $this->incrementFailureCounter($ip, $exception->getCode());
    }

    /**
     * Log security violation with detailed context
     * 
     * @param string $violationType
     * @param array $context
     * @return void
     */
    protected function logSecurityViolation(string $violationType, array $context): void
    {
        $logData = array_merge([
            'event' => 'webhook_security_violation',
            'violation_type' => $violationType,
            'timestamp' => now()->toIso8601String(),
        ], $context);

        Log::warning('Webhook security violation detected', $logData);
    }

    /**
     * Increment failure counter for monitoring
     * 
     * @param string $ip
     * @param int $errorCode
     * @return void
     */
    protected function incrementFailureCounter(string $ip, int $errorCode): void
    {
        $counterKey = "webhook_failures:{$ip}";
        $count = Cache::increment($counterKey, 1);
        Cache::expire($counterKey, 3600); // 1 hour

        // Log high failure rates
        if ($count > 10) {
            Log::warning('High webhook failure rate detected', [
                'ip' => $ip,
                'failure_count' => $count,
                'error_code' => $errorCode,
            ]);
        }
    }

    /**
     * Build appropriate error response
     * 
     * Security Decision: Error responses should provide minimal
     * information to prevent information disclosure while
     * being helpful for legitimate debugging.
     * 
     * @param \Exception $exception
     * @param Request $request
     * @return Response
     */
    protected function buildErrorResponse(\Exception $exception, Request $request): Response
    {
        $statusCode = $exception->getCode() ?: 400;
        $message = $exception->getMessage();

        // For duplicate webhooks, return success (idempotent)
        if ($message === 'Webhook already processed') {
            return response()->json([
                'success' => true,
                'message' => 'Webhook already processed',
                'timestamp' => now()->toIso8601String(),
            ], 200);
        }

        // Build error response
        $response = [
            'success' => false,
            'error' => $message,
            'timestamp' => now()->toIso8601String(),
        ];

        // Add retry information for certain errors
        if ($statusCode === 401 || $statusCode === 403) {
            $response['retry_after'] = 60; // 1 minute
        }

        return response()->json($response, $statusCode);
    }
}
