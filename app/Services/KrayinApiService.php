<?php

namespace App\Services;

use App\Exceptions\KrayinApiException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Enhanced Krayin API Service with retry logic, caching, security measures and error handling
 * 
 * Features:
 * - Exponential backoff retry logic
 * - Comprehensive caching strategy
 * - Rate limiting protection
 * - Security headers and SSL verification
 * - Detailed error handling and logging
 * - Circuit breaker pattern
 * - Request/response logging
 * 
 * @package App\Services
 * @author Bridge Service
 * @version 2.1
 */
class KrayinApiService
{
    private Client $client;
    private int $timeout;
    private int $retryAttempts;
    private int $retryDelay;
    private array $cacheTtl;
    private array $security;
    private array $endpoints;
    private string $rateLimitKey = 'krayin_api_requests';
    private int $maxRequestsPerMinute = 60;
    private int $circuitBreakerThreshold = 5;
    private int $circuitBreakerTimeout = 300; // 5 minutes

    public function __construct()
    {
        $this->timeout = config('services.krayin.timeout', 10);
        $this->retryAttempts = config('services.krayin.retry_attempts', 3);
        $this->retryDelay = config('services.krayin.retry_delay', 1000);
        $this->cacheTtl = config('services.krayin.cache_ttl', []);
        $this->security = config('services.krayin.security', []);
        $this->endpoints = config('services.krayin.endpoints', []);

        $this->initializeClient();
    }

    /**
     * Initialize the Guzzle HTTP client with security configurations
     */
    private function initializeClient(): void
    {
        $this->client = new Client([
            'base_uri' => config('services.krayin.base_url'),
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
                'Authorization' => 'Bearer ' . config('services.krayin.api_token'),
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
     * Create a lead in Krayin with enhanced error handling
     */
    public function createLead(array $data): array
    {
        $this->validateRateLimit();
        $this->checkCircuitBreaker();

        return $this->retryRequest(function () use ($data) {
            $response = $this->client->post($this->endpoints['leads'], [
                'json' => $this->sanitizeData($data),
            ]);

            return $this->handleResponse($response, 'createLead');
        }, 'createLead');
    }

    /**
     * Create an activity for a lead in Krayin
     */
    public function createActivity(int $leadId, array $data): array
    {
        $this->validateRateLimit();
        $this->checkCircuitBreaker();

        return $this->retryRequest(function () use ($leadId, $data) {
            $response = $this->client->post($this->endpoints['activities'], [
                'json' => array_merge($this->sanitizeData($data), [
                    'lead_id' => $leadId,
                ]),
            ]);

            return $this->handleResponse($response, 'createActivity');
        }, 'createActivity');
    }

    /**
     * Update a lead's stage in Krayin with cache invalidation
     */
    public function updateLeadStage(int $leadId, int $stageId): array
    {
        $this->validateRateLimit();
        $this->checkCircuitBreaker();

        $result = $this->retryRequest(function () use ($leadId, $stageId) {
            $response = $this->client->put($this->endpoints['leads'] . "/{$leadId}", [
                'json' => ['lead_pipeline_stage_id' => $stageId],
            ]);

            return $this->handleResponse($response, 'updateLeadStage');
        }, 'updateLeadStage');

        // Invalidate related caches
        $this->invalidateLeadCache($leadId);

        return $result;
    }

    /**
     * Get lead by ID with intelligent caching
     */
    public function getLeadById(int $leadId): array
    {
        $cacheKey = "krayin:lead:{$leadId}";
        $ttl = $this->cacheTtl['lead'] ?? 300;

        return Cache::remember($cacheKey, $ttl, function () use ($leadId) {
            $this->validateRateLimit();
            $this->checkCircuitBreaker();

            $response = $this->client->get($this->endpoints['leads'] . "/{$leadId}");
            return $this->handleResponse($response, 'getLeadById');
        });
    }

    /**
     * Get pipeline stages with long-term caching
     */
    public function getPipelineStages(int $pipelineId): array
    {
        $cacheKey = "krayin:pipeline:{$pipelineId}:stages";
        $ttl = $this->cacheTtl['stages'] ?? 86400;

        return Cache::remember($cacheKey, $ttl, function () use ($pipelineId) {
            $this->validateRateLimit();
            $this->checkCircuitBreaker();

            $response = $this->client->get($this->endpoints['stages'], [
                'query' => ['pipeline_id' => $pipelineId],
            ]);

            return $this->handleResponse($response, 'getPipelineStages');
        });
    }

    /**
     * Get all pipelines with caching
     */
    public function getPipelines(): array
    {
        $cacheKey = 'krayin:pipelines:all';
        $ttl = $this->cacheTtl['pipeline'] ?? 3600;

        return Cache::remember($cacheKey, $ttl, function () {
            $this->validateRateLimit();
            $this->checkCircuitBreaker();

            $response = $this->client->get($this->endpoints['pipelines']);
            return $this->handleResponse($response, 'getPipelines');
        });
    }

    /**
     * Health check for Krayin API
     */
    public function healthCheck(): array
    {
        try {
            $start = microtime(true);
            $response = $this->client->get($this->endpoints['health'], [
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
            } catch (KrayinApiException $e) {
                $lastException = $e;
                $attempt++;

                // Don't retry non-retryable errors
                if (!$e->isRetryable()) {
                    $this->recordCircuitBreakerFailure();
                    throw $e;
                }

                if ($attempt >= $this->retryAttempts) {
                    $this->recordCircuitBreakerFailure();
                    Log::error('Krayin API request failed permanently', [
                        'operation' => $operation,
                        'attempts' => $attempt,
                        'error' => $e->getMessage(),
                        'context' => $e->getContext(),
                    ]);
                    throw $e;
                }

                // Exponential backoff with jitter
                $delay = $this->calculateRetryDelay($attempt);
                Log::warning('Krayin API request failed, retrying', [
                    'operation' => $operation,
                    'attempt' => $attempt,
                    'delay_ms' => $delay,
                    'error' => $e->getMessage(),
                ]);

                usleep($delay * 1000); // Convert to microseconds
            } catch (GuzzleException $e) {
                $lastException = new KrayinApiException(
                    'Krayin API request failed: ' . $e->getMessage(),
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

        throw $lastException ?? new KrayinApiException('Unknown error occurred', 0, null, [], $operation, $attempt);
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

        throw new KrayinApiException(
            "Krayin API error: {$errorMessage}",
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

            Log::warning('Krayin API rate limit exceeded', [
                'retry_after' => $seconds,
                'limit' => $this->maxRequestsPerMinute,
            ]);

            throw new KrayinApiException(
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
        $failures = Cache::get('krayin_circuit_breaker_failures', 0);
        $lastFailure = Cache::get('krayin_circuit_breaker_last_failure', 0);

        if ($failures >= $this->circuitBreakerThreshold) {
            $timeSinceLastFailure = time() - $lastFailure;

            if ($timeSinceLastFailure < $this->circuitBreakerTimeout) {
                $remainingTime = $this->circuitBreakerTimeout - $timeSinceLastFailure;

                Log::warning('Krayin API circuit breaker is open', [
                    'failures' => $failures,
                    'remaining_time' => $remainingTime,
                ]);

                throw new KrayinApiException(
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
        $failures = Cache::get('krayin_circuit_breaker_failures', 0) + 1;
        Cache::put('krayin_circuit_breaker_failures', $failures, 3600);
        Cache::put('krayin_circuit_breaker_last_failure', time(), 3600);
    }

    /**
     * Reset circuit breaker on success
     */
    private function resetCircuitBreaker(): void
    {
        Cache::forget('krayin_circuit_breaker_failures');
        Cache::forget('krayin_circuit_breaker_last_failure');
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
     * Invalidate lead-related caches
     */
    private function invalidateLeadCache(int $leadId): void
    {
        $cacheKeys = [
            "krayin:lead:{$leadId}",
            "krayin:lead:{$leadId}:activities",
            "krayin:lead:{$leadId}:conversations",
        ];

        foreach ($cacheKeys as $key) {
            Cache::forget($key);
        }

        // Also clear tag-based caches if using Redis
        if (config('cache.default') === 'redis') {
            Cache::tags(['krayin', 'leads', "lead:{$leadId}"])->flush();
        }
    }

    /**
     * Log request/response for debugging and monitoring
     */
    private function logRequestResponse(string $operation, int $statusCode, string $responseBody): void
    {
        if (config('app.debug') || $statusCode >= 400) {
            Log::info('Krayin API request/response', [
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
            'circuit_breaker_failures' => Cache::get('krayin_circuit_breaker_failures', 0),
            'circuit_breaker_last_failure' => Cache::get('krayin_circuit_breaker_last_failure', 0),
            'cache_stats' => [
                'lead_ttl' => $this->cacheTtl['lead'] ?? 300,
                'pipeline_ttl' => $this->cacheTtl['pipeline'] ?? 3600,
                'stages_ttl' => $this->cacheTtl['stages'] ?? 86400,
            ],
        ];
    }
}
