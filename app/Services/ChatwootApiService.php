<?php

namespace App\Services;

use App\Exceptions\ChatwootApiException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Enhanced Chatwoot API Service with retry logic, caching, security measures and error handling
 * 
 * Features:
 * - Exponential backoff retry logic
 * - Comprehensive caching strategy
 * - Rate limiting protection
 * - Security headers and SSL verification
 * - Detailed error handling and logging
 * - Circuit breaker pattern
 * - Request/response logging
 * - Webhook signature verification
 * 
 * @package App\Services
 * @author Bridge Service
 * @version 2.1
 */
class ChatwootApiService
{
    private Client $client;
    private int $timeout;
    private int $retryAttempts;
    private int $retryDelay;
    private array $cacheTtl;
    private array $security;
    private array $endpoints;
    private string $rateLimitKey = 'chatwoot_api_requests';
    private int $maxRequestsPerMinute = 60;
    private int $circuitBreakerThreshold = 5;
    private int $circuitBreakerTimeout = 300; // 5 minutes

    public function __construct()
    {
        $this->timeout = config('services.chatwoot.timeout', 10);
        $this->retryAttempts = config('services.chatwoot.retry_attempts', 3);
        $this->retryDelay = config('services.chatwoot.retry_delay', 1000);
        $this->cacheTtl = config('services.chatwoot.cache_ttl', []);
        $this->security = config('services.chatwoot.security', []);
        $this->endpoints = config('services.chatwoot.endpoints', []);

        $this->initializeClient();
    }

    /**
     * Initialize the Guzzle HTTP client with security configurations
     */
    private function initializeClient(): void
    {
        $this->client = new Client([
            'base_uri' => config('services.chatwoot.base_url'),
            'timeout' => $this->timeout,
            'connect_timeout' => 5,
            'verify' => $this->security['verify_ssl'] ?? true,
            'allow_redirects' => [
                'max' => $this->security['max_redirects'] ?? 3,
                'strict' => true,
                'referer' => true,
                'protocols' => ['https'],
            ],
            'headers' => [
                'Authorization' => 'Bearer ' . config('services.chatwoot.api_token'),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => $this->security['user_agent'] ?? 'Bridge-Service/2.1',
                'X-Request-ID' => Str::uuid()->toString(),
                'X-Client-Version' => '2.1',
            ],
            'http_errors' => false, // Handle HTTP errors manually
        ]);
    }

    /**
     * Get conversation by ID with caching
     */
    public function getConversation(int $conversationId): array
    {
        $cacheKey = "chatwoot:conversation:{$conversationId}";
        $ttl = $this->cacheTtl['conversation'] ?? 300;

        return Cache::remember($cacheKey, $ttl, function () use ($conversationId) {
            $this->validateRateLimit();
            $this->checkCircuitBreaker();

            $response = $this->client->get($this->endpoints['conversations'] . "/{$conversationId}");
            return $this->handleResponse($response, 'getConversation');
        });
    }

    /**
     * Get messages for a conversation with pagination
     */
    public function getMessages(int $conversationId, array $params = []): array
    {
        $cacheKey = "chatwoot:messages:{$conversationId}:" . md5(serialize($params));
        $ttl = $this->cacheTtl['messages'] ?? 60; // Shorter TTL for messages

        return Cache::remember($cacheKey, $ttl, function () use ($conversationId, $params) {
            $this->validateRateLimit();
            $this->checkCircuitBreaker();

            $response = $this->client->get($this->endpoints['messages'], [
                'query' => array_merge($params, [
                    'conversation_id' => $conversationId,
                ]),
            ]);

            return $this->handleResponse($response, 'getMessages');
        });
    }

    /**
     * Get contact by ID with caching
     */
    public function getContact(int $contactId): array
    {
        $cacheKey = "chatwoot:contact:{$contactId}";
        $ttl = $this->cacheTtl['contact'] ?? 600;

        return Cache::remember($cacheKey, $ttl, function () use ($contactId) {
            $this->validateRateLimit();
            $this->checkCircuitBreaker();

            $response = $this->client->get($this->endpoints['contacts'] . "/{$contactId}");
            return $this->handleResponse($response, 'getContact');
        });
    }

    /**
     * Create a new contact
     */
    public function createContact(array $data): array
    {
        $this->validateRateLimit();
        $this->checkCircuitBreaker();

        return $this->retryRequest(function () use ($data) {
            $response = $this->client->post($this->endpoints['contacts'], [
                'json' => $this->sanitizeData($data),
            ]);

            return $this->handleResponse($response, 'createContact');
        }, 'createContact');
    }

    /**
     * Update contact information
     */
    public function updateContact(int $contactId, array $data): array
    {
        $this->validateRateLimit();
        $this->checkCircuitBreaker();

        $result = $this->retryRequest(function () use ($contactId, $data) {
            $response = $this->client->put($this->endpoints['contacts'] . "/{$contactId}", [
                'json' => $this->sanitizeData($data),
            ]);

            return $this->handleResponse($response, 'updateContact');
        }, 'updateContact');

        // Invalidate contact cache
        $this->invalidateContactCache($contactId);

        return $result;
    }

    /**
     * Create a new message in a conversation
     */
    public function createMessage(int $conversationId, array $data): array
    {
        $this->validateRateLimit();
        $this->checkCircuitBreaker();

        $result = $this->retryRequest(function () use ($conversationId, $data) {
            $response = $this->client->post($this->endpoints['messages'], [
                'json' => array_merge($this->sanitizeData($data), [
                    'conversation_id' => $conversationId,
                ]),
            ]);

            return $this->handleResponse($response, 'createMessage');
        }, 'createMessage');

        // Invalidate conversation cache
        $this->invalidateConversationCache($conversationId);

        return $result;
    }

    /**
     * Update conversation status
     */
    public function updateConversationStatus(int $conversationId, string $status): array
    {
        $this->validateRateLimit();
        $this->checkCircuitBreaker();

        $result = $this->retryRequest(function () use ($conversationId, $status) {
            $response = $this->client->put($this->endpoints['conversations'] . "/{$conversationId}", [
                'json' => ['status' => $status],
            ]);

            return $this->handleResponse($response, 'updateConversationStatus');
        }, 'updateConversationStatus');

        // Invalidate conversation cache
        $this->invalidateConversationCache($conversationId);

        return $result;
    }

    /**
     * Get account information
     */
    public function getAccount(int $accountId): array
    {
        $cacheKey = "chatwoot:account:{$accountId}";
        $ttl = $this->cacheTtl['account'] ?? 3600;

        return Cache::remember($cacheKey, $ttl, function () use ($accountId) {
            $this->validateRateLimit();
            $this->checkCircuitBreaker();

            $response = $this->client->get($this->endpoints['accounts'] . "/{$accountId}");
            return $this->handleResponse($response, 'getAccount');
        });
    }

    /**
     * Health check for Chatwoot API
     */
    public function healthCheck(): array
    {
        try {
            $start = microtime(true);
            $response = $this->client->get('/health', [
                'timeout' => 5,
            ]);
            $duration = round((microtime(true) - $start) * 1000, 2);

            return [
                'status' => $response->getStatusCode() === 200 ? 'ok' : 'error',
                'response_time_ms' => $duration,
                'code' => $response->getStatusCode(),
                'message' => $response->getStatusCode() === 200 ? 'API is healthy' : 'API returned error status',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'response_time_ms' => null,
                'code' => 0,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Verify webhook signature for security
     */
    public function verifyWebhookSignature(string $payload, string $signature, string $timestamp): bool
    {
        $secret = config('services.chatwoot.webhook_secret');

        if (!$secret) {
            Log::warning('Chatwoot webhook secret not configured');
            return false;
        }

        // Check timestamp tolerance (prevent replay attacks)
        $timestampTolerance = config('services.chatwoot.webhook_security.timestamp_tolerance', 300);
        if (abs(time() - $timestamp) > $timestampTolerance) {
            Log::warning('Chatwoot webhook timestamp expired', [
                'timestamp' => $timestamp,
                'current' => time(),
                'tolerance' => $timestampTolerance,
            ]);
            return false;
        }

        // Calculate expected signature
        $signedPayload = $timestamp . '.' . $payload;
        $expectedSignature = 'sha256=' . hash_hmac('sha256', $signedPayload, $secret);

        // Use hash_equals for timing attack protection
        $isValid = hash_equals($expectedSignature, $signature);

        if (!$isValid) {
            Log::warning('Chatwoot webhook signature verification failed', [
                'expected' => $expectedSignature,
                'received' => $signature,
            ]);
        }

        return $isValid;
    }

    /**
     * Enhanced retry logic with exponential backoff and circuit breaker
     */
    private function retryRequest(callable $callback, string $operation): mixed
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->retryAttempts) {
            try {
                $result = $callback();

                // Reset circuit breaker on success
                $this->resetCircuitBreaker();

                return $result;
            } catch (ChatwootApiException $e) {
                $lastException = $e;
                $attempt++;

                // Don't retry non-retryable errors
                if (!$e->isRetryable()) {
                    $this->recordCircuitBreakerFailure();
                    throw $e;
                }

                if ($attempt >= $this->retryAttempts) {
                    $this->recordCircuitBreakerFailure();
                    Log::error('Chatwoot API request failed permanently', [
                        'operation' => $operation,
                        'attempts' => $attempt,
                        'error' => $e->getMessage(),
                        'context' => $e->getContext(),
                    ]);
                    throw $e;
                }

                // Exponential backoff with jitter
                $delay = $this->calculateRetryDelay($attempt);
                Log::warning('Chatwoot API request failed, retrying', [
                    'operation' => $operation,
                    'attempt' => $attempt,
                    'delay_ms' => $delay,
                    'error' => $e->getMessage(),
                ]);

                usleep($delay * 1000); // Convert to microseconds
            } catch (GuzzleException $e) {
                $lastException = new ChatwootApiException(
                    'Chatwoot API request failed: ' . $e->getMessage(),
                    $e->getCode(),
                    $e,
                    ['operation' => $operation, 'attempt' => $attempt],
                    $operation,
                    $attempt,
                    $e instanceof RequestException ? $e : null
                );

                if (!$lastException->isRetryable()) {
                    $this->recordCircuitBreakerFailure();
                    throw $lastException;
                }

                $attempt++;
                if ($attempt >= $this->retryAttempts) {
                    $this->recordCircuitBreakerFailure();
                    throw $lastException;
                }

                $delay = $this->calculateRetryDelay($attempt);
                usleep($delay * 1000);
            }
        }

        throw $lastException ?? new ChatwootApiException('Unknown error occurred', 0, null, [], $operation, $attempt);
    }

    /**
     * Handle API response with comprehensive error handling
     */
    private function handleResponse($response, string $operation): array
    {
        $statusCode = $response->getStatusCode();
        $body = $response->getBody()->getContents();
        $data = json_decode($body, true) ?? [];

        // Log request/response for debugging
        $this->logRequestResponse($operation, $statusCode, $body);

        if ($statusCode >= 200 && $statusCode < 300) {
            return $data;
        }

        // Handle specific error cases
        $errorMessage = $data['message'] ?? $data['error'] ?? 'Unknown API error';
        $errorCode = $data['error_code'] ?? 'UNKNOWN_ERROR';

        throw new ChatwootApiException(
            "Chatwoot API error: {$errorMessage}",
            $statusCode,
            null,
            [
                'operation' => $operation,
                'status_code' => $statusCode,
                'response_body' => $body,
                'error_code' => $errorCode,
            ],
            $operation,
            0,
            null
        );
    }

    /**
     * Calculate retry delay with exponential backoff and jitter
     */
    private function calculateRetryDelay(int $attempt): int
    {
        $baseDelay = $this->retryDelay;
        $exponentialDelay = $baseDelay * pow(2, $attempt - 1);
        $jitter = rand(0, 1000); // Add up to 1 second of jitter

        return min($exponentialDelay + $jitter, 30000); // Cap at 30 seconds
    }

    /**
     * Validate rate limiting
     */
    private function validateRateLimit(): void
    {
        if (RateLimiter::tooManyAttempts($this->rateLimitKey, $this->maxRequestsPerMinute)) {
            $seconds = RateLimiter::availableIn($this->rateLimitKey);

            Log::warning('Chatwoot API rate limit exceeded', [
                'retry_after' => $seconds,
                'limit' => $this->maxRequestsPerMinute,
            ]);

            throw new ChatwootApiException(
                "Rate limit exceeded. Try again in {$seconds} seconds.",
                429,
                null,
                ['retry_after' => $seconds],
                'rate_limit_check',
                0,
                null
            );
        }

        RateLimiter::hit($this->rateLimitKey, 60);
    }

    /**
     * Check circuit breaker status
     */
    private function checkCircuitBreaker(): void
    {
        $failures = Cache::get('chatwoot_circuit_breaker_failures', 0);
        $lastFailure = Cache::get('chatwoot_circuit_breaker_last_failure', 0);

        if ($failures >= $this->circuitBreakerThreshold) {
            $timeSinceLastFailure = time() - $lastFailure;

            if ($timeSinceLastFailure < $this->circuitBreakerTimeout) {
                $remainingTime = $this->circuitBreakerTimeout - $timeSinceLastFailure;

                Log::warning('Chatwoot API circuit breaker is open', [
                    'failures' => $failures,
                    'remaining_time' => $remainingTime,
                ]);

                throw new ChatwootApiException(
                    "Circuit breaker is open. Try again in {$remainingTime} seconds.",
                    503,
                    null,
                    ['circuit_breaker_open' => true, 'remaining_time' => $remainingTime],
                    'circuit_breaker_check',
                    0,
                    null
                );
            }
        }
    }

    /**
     * Record circuit breaker failure
     */
    private function recordCircuitBreakerFailure(): void
    {
        $failures = Cache::get('chatwoot_circuit_breaker_failures', 0) + 1;
        Cache::put('chatwoot_circuit_breaker_failures', $failures, 3600);
        Cache::put('chatwoot_circuit_breaker_last_failure', time(), 3600);
    }

    /**
     * Reset circuit breaker on success
     */
    private function resetCircuitBreaker(): void
    {
        Cache::forget('chatwoot_circuit_breaker_failures');
        Cache::forget('chatwoot_circuit_breaker_last_failure');
    }

    /**
     * Sanitize data before sending to API
     */
    private function sanitizeData(array $data): array
    {
        // Remove null values and empty strings
        $data = array_filter($data, function ($value) {
            return $value !== null && $value !== '';
        });

        // Sanitize string values
        array_walk_recursive($data, function (&$value) {
            if (is_string($value)) {
                $value = trim($value);
                // Remove potential XSS attempts
                $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            }
        });

        return $data;
    }

    /**
     * Invalidate contact-related caches
     */
    private function invalidateContactCache(int $contactId): void
    {
        $cacheKeys = [
            "chatwoot:contact:{$contactId}",
            "chatwoot:contact:{$contactId}:conversations",
        ];

        foreach ($cacheKeys as $key) {
            Cache::forget($key);
        }

        // Also clear tag-based caches if using Redis
        if (config('cache.default') === 'redis') {
            Cache::tags(['chatwoot', 'contacts', "contact:{$contactId}"])->flush();
        }
    }

    /**
     * Invalidate conversation-related caches
     */
    private function invalidateConversationCache(int $conversationId): void
    {
        $cacheKeys = [
            "chatwoot:conversation:{$conversationId}",
            "chatwoot:messages:{$conversationId}:*",
        ];

        foreach ($cacheKeys as $key) {
            Cache::forget($key);
        }

        // Also clear tag-based caches if using Redis
        if (config('cache.default') === 'redis') {
            Cache::tags(['chatwoot', 'conversations', "conversation:{$conversationId}"])->flush();
        }
    }

    /**
     * Log request/response for debugging and monitoring
     */
    private function logRequestResponse(string $operation, int $statusCode, string $responseBody): void
    {
        if (config('app.debug') || $statusCode >= 400) {
            Log::info('Chatwoot API request/response', [
                'operation' => $operation,
                'status_code' => $statusCode,
                'response_size' => strlen($responseBody),
                'response_preview' => Str::limit($responseBody, 500),
            ]);
        }
    }

    /**
     * Get service statistics for monitoring
     */
    public function getStats(): array
    {
        return [
            'rate_limit_remaining' => RateLimiter::remaining($this->rateLimitKey, $this->maxRequestsPerMinute),
            'circuit_breaker_failures' => Cache::get('chatwoot_circuit_breaker_failures', 0),
            'circuit_breaker_last_failure' => Cache::get('chatwoot_circuit_breaker_last_failure', 0),
            'cache_stats' => [
                'conversation_ttl' => $this->cacheTtl['conversation'] ?? 300,
                'messages_ttl' => $this->cacheTtl['messages'] ?? 60,
                'contact_ttl' => $this->cacheTtl['contact'] ?? 600,
                'account_ttl' => $this->cacheTtl['account'] ?? 3600,
            ],
        ];
    }
}
