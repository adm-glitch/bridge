<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ThrottleAuth
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $type = 'default'): Response
    {
        $rateLimits = $this->getRateLimits($type);
        $key = $this->getRateLimitKey($request, $type);

        foreach ($rateLimits as $limit) {
            $rateLimitKey = "{$key}:{$limit['window']}";

            if (RateLimiter::tooManyAttempts($rateLimitKey, $limit['max_attempts'])) {
                $seconds = RateLimiter::availableIn($rateLimitKey);

                Log::warning('Rate limit exceeded', [
                    'type' => $type,
                    'key' => $rateLimitKey,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'retry_after' => $seconds,
                    'limit' => $limit['max_attempts'],
                    'window' => $limit['window']
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'Rate limit exceeded',
                    'error_code' => 'RATE_LIMIT_EXCEEDED',
                    'retry_after' => $seconds,
                    'limit' => $limit['max_attempts'],
                    'window' => $limit['window']
                ], 429);
            }

            RateLimiter::hit($rateLimitKey, $limit['decay_minutes'] * 60);
        }

        $response = $next($request);

        // Add rate limit headers
        $this->addRateLimitHeaders($response, $rateLimits, $key);

        return $response;
    }

    /**
     * Get rate limits based on type
     * 
     * Rate limits are configured according to API specifications v2.1:
     * - Webhooks: 100 req/min, 1000 req/hour per IP
     * - Data APIs: 60 req/min, 600 req/hour per user
     * - AI Insights: 30 req/min, 300 req/hour per user
     * - LGPD Exports: 5 req/min, 20 req/hour per user
     * - Authentication: 5 req/min, 20 req/hour per IP
     */
    private function getRateLimits(string $type): array
    {
        $limits = [
            'login' => [
                [
                    'max_attempts' => 5,
                    'decay_minutes' => 1,
                    'window' => '1min'
                ],
                [
                    'max_attempts' => 20,
                    'decay_minutes' => 60,
                    'window' => '1hour'
                ]
            ],
            'refresh' => [
                [
                    'max_attempts' => 5,
                    'decay_minutes' => 1,
                    'window' => '1min'
                ],
                [
                    'max_attempts' => 20,
                    'decay_minutes' => 60,
                    'window' => '1hour'
                ]
            ],
            'api' => [
                [
                    'max_attempts' => 60,
                    'decay_minutes' => 1,
                    'window' => '1min'
                ],
                [
                    'max_attempts' => 600,
                    'decay_minutes' => 60,
                    'window' => '1hour'
                ]
            ],
            'ai' => [
                [
                    'max_attempts' => 30,
                    'decay_minutes' => 1,
                    'window' => '1min'
                ],
                [
                    'max_attempts' => 300,
                    'decay_minutes' => 60,
                    'window' => '1hour'
                ]
            ],
            'lgpd' => [
                [
                    'max_attempts' => 5,
                    'decay_minutes' => 1,
                    'window' => '1min'
                ],
                [
                    'max_attempts' => 20,
                    'decay_minutes' => 60,
                    'window' => '1hour'
                ]
            ],
            'webhook' => [
                [
                    'max_attempts' => 100,
                    'decay_minutes' => 1,
                    'window' => '1min'
                ],
                [
                    'max_attempts' => 1000,
                    'decay_minutes' => 60,
                    'window' => '1hour'
                ]
            ],
            'export' => [
                [
                    'max_attempts' => 5,
                    'decay_minutes' => 1,
                    'window' => '1min'
                ],
                [
                    'max_attempts' => 20,
                    'decay_minutes' => 60,
                    'window' => '1hour'
                ]
            ],
            'default' => [
                [
                    'max_attempts' => 30,
                    'decay_minutes' => 1,
                    'window' => '1min'
                ]
            ]
        ];

        return $limits[$type] ?? $limits['default'];
    }

    /**
     * Get rate limit key based on request and type
     */
    private function getRateLimitKey(Request $request, string $type): string
    {
        $ip = $request->ip();
        $user = $request->user();

        // For authenticated users, use user ID
        if ($user && in_array($type, ['api', 'default'])) {
            return "user:{$user->id}";
        }

        // For unauthenticated requests, use IP
        return "ip:{$ip}";
    }

    /**
     * Add rate limit headers to response
     */
    private function addRateLimitHeaders(Response $response, array $rateLimits, string $key): void
    {
        $primaryLimit = $rateLimits[0];
        $rateLimitKey = "{$key}:{$primaryLimit['window']}";

        $response->headers->set('X-RateLimit-Limit', $primaryLimit['max_attempts']);
        $response->headers->set('X-RateLimit-Remaining', max(0, $primaryLimit['max_attempts'] - RateLimiter::attempts($rateLimitKey)));
        $response->headers->set('X-RateLimit-Reset', now()->addMinutes($primaryLimit['decay_minutes'])->timestamp);
    }
}
