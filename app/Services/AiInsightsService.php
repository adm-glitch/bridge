<?php

namespace App\Services;

use App\Exceptions\AiInsightsException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * AI Insights Service with retry logic, caching, rate limiting, circuit breaker, and security headers
 */
class AiInsightsService
{
    private Client $client;
    private int $timeout;
    private int $retryAttempts;
    private int $retryDelay;
    private array $cacheTtl;
    private string $rateLimitKey = 'ai_insights_requests';
    private int $maxRequestsPerMinute = 30; // costly operations
    private int $circuitBreakerThreshold = 5;
    private int $circuitBreakerTimeout = 300; // seconds

    public function __construct()
    {
        $this->timeout = config('services.ai_insights.timeout', 15);
        $this->retryAttempts = config('services.ai_insights.retry_attempts', 3);
        $this->retryDelay = config('services.ai_insights.retry_delay', 1000);
        $this->cacheTtl = config('services.ai_insights.cache_ttl', []);
        $this->initializeClient();
    }

    private function initializeClient(): void
    {
        $this->client = new Client([
            'base_uri' => config('services.ai_insights.base_url'),
            'timeout' => $this->timeout,
            'connect_timeout' => 5,
            'verify' => config('services.ai_insights.security.verify_ssl', true),
            'allow_redirects' => [
                'max' => config('services.ai_insights.security.max_redirects', 3),
                'strict' => true,
                'referer' => true,
                'protocols' => ['https'],
            ],
            'headers' => [
                'Authorization' => 'Bearer ' . config('services.ai_insights.api_token'),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => config('services.ai_insights.security.user_agent', 'Bridge-Service/2.1'),
                'X-Request-ID' => Str::uuid()->toString(),
                'X-Client-Version' => '2.1',
            ],
            'http_errors' => false,
        ]);
    }

    /** Get current insights for a lead */
    public function getCurrentInsights(int $leadId, array $params = []): array
    {
        $cacheKey = "ai:insights:current:{$leadId}:" . md5(serialize($params));
        $ttl = $this->cacheTtl['current'] ?? 3600; // 1 hour

        return Cache::remember($cacheKey, $ttl, function () use ($leadId, $params) {
            $this->validateRateLimit();
            $this->checkCircuitBreaker();

            $response = $this->client->get('/api/v1/ai/insights/' . $leadId, [
                'query' => $params,
            ]);

            return $this->handleResponse($response, 'getCurrentInsights');
        });
    }

    /** Get historical insights list for a lead */
    public function getHistoricalInsights(int $leadId, array $params = []): array
    {
        $cacheKey = "ai:insights:historical:{$leadId}:" . md5(serialize($params));
        $ttl = $this->cacheTtl['historical'] ?? 7200; // 2 hours

        return Cache::remember($cacheKey, $ttl, function () use ($leadId, $params) {
            $this->validateRateLimit();
            $this->checkCircuitBreaker();

            $response = $this->client->get('/api/v1/ai/insights/' . $leadId, [
                'query' => array_merge($params, ['include_history' => true]),
            ]);

            return $this->handleResponse($response, 'getHistoricalInsights');
        });
    }

    /** Force refresh current insights (bypass cache) */
    public function refreshCurrentInsights(int $leadId, array $params = []): array
    {
        $this->validateRateLimit();
        $this->checkCircuitBreaker();

        $response = $this->client->get('/api/v1/ai/insights/' . $leadId, [
            'query' => array_merge($params, ['_bypass_cache' => 1]),
        ]);

        $data = $this->handleResponse($response, 'refreshCurrentInsights');
        $this->invalidateCurrentCache($leadId);
        return $data;
    }

    /** Health check for AI insights back-end */
    public function healthCheck(): array
    {
        try {
            $start = microtime(true);
            $response = $this->client->get('/health', ['timeout' => 5]);
            $duration = round((microtime(true) - $start) * 1000, 2);
            return [
                'status' => $response->getStatusCode() === 200 ? 'ok' : 'error',
                'response_time_ms' => $duration,
                'code' => $response->getStatusCode(),
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

    private function retryRequest(callable $callback, string $operation): mixed
    {
        $attempt = 0;
        $last = null;
        while ($attempt < $this->retryAttempts) {
            try {
                $result = $callback();
                $this->resetCircuitBreaker();
                return $result;
            } catch (AiInsightsException $e) {
                $last = $e;
                $attempt++;
                if (!$e->isRetryable()) {
                    $this->recordCircuitBreakerFailure();
                    throw $e;
                }
                if ($attempt >= $this->retryAttempts) {
                    $this->recordCircuitBreakerFailure();
                    throw $e;
                }
                usleep($this->calculateRetryDelay($attempt) * 1000);
            } catch (GuzzleException $e) {
                $last = new AiInsightsException(
                    'AI Insights request failed: ' . $e->getMessage(),
                    $e->getCode(),
                    $e,
                    ['operation' => $operation, 'attempt' => $attempt],
                    $operation,
                    $attempt,
                    $e instanceof RequestException ? $e : null
                );
                if (!$last->isRetryable()) {
                    $this->recordCircuitBreakerFailure();
                    throw $last;
                }
                $attempt++;
                if ($attempt >= $this->retryAttempts) {
                    $this->recordCircuitBreakerFailure();
                    throw $last;
                }
                usleep($this->calculateRetryDelay($attempt) * 1000);
            }
        }
        throw $last ?? new AiInsightsException('Unknown error occurred', 0, null, [], $operation, $attempt);
    }

    private function handleResponse($response, string $operation): array
    {
        $statusCode = $response->getStatusCode();
        $body = $response->getBody()->getContents();
        $data = json_decode($body, true) ?? [];

        $this->logRequestResponse($operation, $statusCode, $body);

        if ($statusCode >= 200 && $statusCode < 300) {
            return $data;
        }

        $errorMessage = $data['message'] ?? $data['error'] ?? 'Unknown API error';
        $errorCode = $data['error_code'] ?? 'UNKNOWN_ERROR';

        throw new AiInsightsException(
            "AI Insights API error: {$errorMessage}",
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

    private function calculateRetryDelay(int $attempt): int
    {
        $base = $this->retryDelay;
        $exp = $base * pow(2, $attempt - 1);
        $jitter = rand(0, 1000);
        return min($exp + $jitter, 30000);
    }

    private function validateRateLimit(): void
    {
        if (RateLimiter::tooManyAttempts($this->rateLimitKey, $this->maxRequestsPerMinute)) {
            $seconds = RateLimiter::availableIn($this->rateLimitKey);
            Log::warning('AI Insights rate limit exceeded', ['retry_after' => $seconds, 'limit' => $this->maxRequestsPerMinute]);
            throw new AiInsightsException(
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

    private function checkCircuitBreaker(): void
    {
        $failures = Cache::get('ai_insights_cb_failures', 0);
        $lastFailure = Cache::get('ai_insights_cb_last', 0);
        if ($failures >= $this->circuitBreakerThreshold) {
            $elapsed = time() - $lastFailure;
            if ($elapsed < $this->circuitBreakerTimeout) {
                $remaining = $this->circuitBreakerTimeout - $elapsed;
                Log::warning('AI Insights circuit breaker open', ['remaining' => $remaining]);
                throw new AiInsightsException(
                    "Circuit breaker is open. Try again in {$remaining} seconds.",
                    503,
                    null,
                    ['circuit_breaker_open' => true, 'remaining_time' => $remaining],
                    'circuit_breaker_check',
                    0,
                    null
                );
            }
        }
    }

    private function recordCircuitBreakerFailure(): void
    {
        $failures = Cache::get('ai_insights_cb_failures', 0) + 1;
        Cache::put('ai_insights_cb_failures', $failures, 3600);
        Cache::put('ai_insights_cb_last', time(), 3600);
    }

    private function resetCircuitBreaker(): void
    {
        Cache::forget('ai_insights_cb_failures');
        Cache::forget('ai_insights_cb_last');
    }

    private function invalidateCurrentCache(int $leadId): void
    {
        Cache::forget("ai:insights:current:{$leadId}");
    }

    private function logRequestResponse(string $operation, int $statusCode, string $responseBody): void
    {
        if (config('app.debug') || $statusCode >= 400) {
            Log::info('AI Insights request/response', [
                'operation' => $operation,
                'status_code' => $statusCode,
                'response_size' => strlen($responseBody),
                'response_preview' => Str::limit($responseBody, 500),
            ]);
        }
    }

    public function getStats(): array
    {
        return [
            'rate_limit_remaining' => RateLimiter::remaining($this->rateLimitKey, $this->maxRequestsPerMinute),
            'circuit_breaker_failures' => Cache::get('ai_insights_cb_failures', 0),
            'circuit_breaker_last_failure' => Cache::get('ai_insights_cb_last', 0),
            'cache_ttl' => [
                'current' => $this->cacheTtl['current'] ?? 3600,
                'historical' => $this->cacheTtl['historical'] ?? 7200,
            ],
        ];
    }
}
