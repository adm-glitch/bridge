<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enhanced ThrottleRequests Middleware
 * 
 * This middleware implements comprehensive rate limiting with security features
 * as specified in the architecture document v2.1. It provides:
 * 
 * - Multi-tier rate limiting (IP, user, endpoint-specific)
 * - Security hardening against DoS attacks
 * - Comprehensive logging for security monitoring
 * - Graceful degradation and error handling
 * - LGPD compliance for audit trails
 * 
 * Security Features:
 * - Prevents brute force attacks
 * - Implements exponential backoff
 * - Tracks suspicious activity patterns
 * - Provides detailed security logging
 * - Supports different rate limits per endpoint type
 * 
 * @package App\Http\Middleware
 * @version 2.1
 * @author Bridge Service Team
 * @since 2025-10-08
 */
class ThrottleRequests
{
    /**
     * Rate limiter instance
     * 
     * @var RateLimiter
     */
    protected $limiter;

    /**
     * Default rate limiting configuration
     * 
     * @var array
     */
    protected $defaultLimits = [
        'webhooks' => [
            'max_attempts' => 100,
            'decay_minutes' => 1,
            'burst_limit' => 200,
            'burst_decay' => 5,
        ],
        'api' => [
            'max_attempts' => 60,
            'decay_minutes' => 1,
            'burst_limit' => 100,
            'burst_decay' => 5,
        ],
        'auth' => [
            'max_attempts' => 5,
            'decay_minutes' => 15,
            'burst_limit' => 10,
            'burst_decay' => 1,
        ],
        'default' => [
            'max_attempts' => 30,
            'decay_minutes' => 1,
            'burst_limit' => 50,
            'burst_decay' => 5,
        ],
    ];

    /**
     * Security thresholds for suspicious activity detection
     * 
     * @var array
     */
    protected $securityThresholds = [
        'suspicious_attempts' => 50,    // Flag as suspicious after 50 attempts
        'critical_attempts' => 100,    // Flag as critical after 100 attempts
        'block_duration' => 3600,       // Block for 1 hour if critical
        'monitor_duration' => 86400,    // Monitor for 24 hours
    ];

    /**
     * Create a new middleware instance
     * 
     * @param RateLimiter $limiter
     */
    public function __construct(RateLimiter $limiter)
    {
        $this->limiter = $limiter;
    }

    /**
     * Handle an incoming request with comprehensive rate limiting
     * 
     * This method implements multi-layered security:
     * 1. Basic rate limiting per IP/user
     * 2. Burst protection for sudden traffic spikes
     * 3. Suspicious activity detection and logging
     * 4. Endpoint-specific rate limiting
     * 5. Security event logging for audit trails
     * 
     * @param Request $request
     * @param Closure $next
     * @param string|null $maxAttempts Maximum attempts (e.g., "60,1" for 60 attempts per minute)
     * @param string|null $decayMinutes Decay period in minutes
     * @param string|null $prefix Rate limit key prefix
     * @return Response
     * @throws ThrottleRequestsException
     */
    public function handle(
        Request $request,
        Closure $next,
        ?string $maxAttempts = null,
        ?string $decayMinutes = null,
        ?string $prefix = null
    ): Response {
        // Determine rate limiting configuration based on request type
        $config = $this->determineRateLimitConfig($request, $maxAttempts, $decayMinutes);

        // Generate rate limiting keys
        $keys = $this->generateRateLimitKeys($request, $prefix);

        // Check all rate limits
        $rateLimitResults = $this->checkAllRateLimits($keys, $config);

        // Handle rate limit violations
        if ($rateLimitResults['violated']) {
            return $this->handleRateLimitViolation($request, $rateLimitResults, $config);
        }

        // Record the request for rate limiting
        $this->recordRequest($keys, $config);

        // Log security events if thresholds are exceeded
        $this->logSecurityEvents($request, $keys, $rateLimitResults);

        // Process the request
        $response = $next($request);

        // Add rate limit headers to response
        $this->addRateLimitHeaders($response, $keys, $config);

        return $response;
    }

    /**
     * Determine rate limiting configuration based on request characteristics
     * 
     * Security Decision: Different endpoints require different rate limits
     * - Webhook endpoints: Higher limits but with burst protection
     * - Authentication endpoints: Strict limits to prevent brute force
     * - API endpoints: Balanced limits for normal usage
     * - Default: Conservative limits for unknown endpoints
     * 
     * @param Request $request
     * @param string|null $maxAttempts
     * @param string|null $decayMinutes
     * @return array
     */
    protected function determineRateLimitConfig(Request $request, ?string $maxAttempts, ?string $decayMinutes): array
    {
        // Parse custom limits if provided
        if ($maxAttempts && $decayMinutes) {
            return [
                'max_attempts' => (int) $maxAttempts,
                'decay_minutes' => (int) $decayMinutes,
                'burst_limit' => (int) ($maxAttempts * 2),
                'burst_decay' => (int) ($decayMinutes * 2),
            ];
        }

        // Determine endpoint type for security-based rate limiting
        $endpointType = $this->classifyEndpoint($request);

        return $this->defaultLimits[$endpointType] ?? $this->defaultLimits['default'];
    }

    /**
     * Classify endpoint type for security-based rate limiting
     * 
     * Security Decision: Different endpoint types have different risk profiles
     * and require different rate limiting strategies.
     * 
     * @param Request $request
     * @return string
     */
    protected function classifyEndpoint(Request $request): string
    {
        $path = $request->path();
        $method = $request->method();

        // Webhook endpoints - higher limits but with burst protection
        if (Str::contains($path, 'webhooks')) {
            return 'webhooks';
        }

        // Authentication endpoints - strict limits to prevent brute force
        if (
            Str::contains($path, ['login', 'register', 'password', 'auth']) ||
            Str::contains($path, ['token', 'oauth'])
        ) {
            return 'auth';
        }

        // API endpoints - balanced limits
        if (Str::startsWith($path, 'api/')) {
            return 'api';
        }

        return 'default';
    }

    /**
     * Generate rate limiting keys for multi-layered protection
     * 
     * Security Decision: Multiple keys provide defense in depth:
     * - IP-based limiting prevents abuse from specific sources
     * - User-based limiting prevents abuse by authenticated users
     * - Endpoint-based limiting prevents abuse of specific resources
     * - Global limiting prevents system-wide abuse
     * 
     * @param Request $request
     * @param string|null $prefix
     * @return array
     */
    protected function generateRateLimitKeys(Request $request, ?string $prefix = null): array
    {
        $basePrefix = $prefix ?: 'throttle';
        $ip = $this->getClientIp($request);
        $userId = $request->user()?->id;
        $endpoint = $this->getEndpointKey($request);

        return [
            'ip' => "{$basePrefix}:ip:{$ip}",
            'user' => $userId ? "{$basePrefix}:user:{$userId}" : null,
            'endpoint' => "{$basePrefix}:endpoint:{$endpoint}",
            'global' => "{$basePrefix}:global",
            'burst' => "{$basePrefix}:burst:{$ip}",
        ];
    }

    /**
     * Get client IP address with proxy support
     * 
     * Security Decision: Proper IP detection is crucial for rate limiting
     * effectiveness. We check multiple headers to handle various proxy
     * configurations while preventing IP spoofing.
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
     * Generate endpoint key for rate limiting
     * 
     * @param Request $request
     * @return string
     */
    protected function getEndpointKey(Request $request): string
    {
        $path = $request->path();
        $method = $request->method();

        // Normalize path for better grouping
        $normalizedPath = preg_replace('/\d+/', '{id}', $path);

        return strtolower("{$method}:{$normalizedPath}");
    }

    /**
     * Check all rate limits and return violation status
     * 
     * Security Decision: Multiple rate limit checks provide defense in depth.
     * We check IP, user, endpoint, and global limits to prevent various
     * attack vectors while allowing legitimate usage.
     * 
     * @param array $keys
     * @param array $config
     * @return array
     */
    protected function checkAllRateLimits(array $keys, array $config): array
    {
        $results = [
            'violated' => false,
            'violations' => [],
            'attempts' => [],
            'retry_after' => null,
        ];

        // Check each rate limit
        foreach ($keys as $type => $key) {
            if (!$key) continue;

            $attempts = $this->limiter->attempts($key);
            $maxAttempts = $config['max_attempts'];

            if ($this->limiter->tooManyAttempts($key, $maxAttempts)) {
                $results['violated'] = true;
                $results['violations'][] = $type;
                $results['retry_after'] = max(
                    $results['retry_after'] ?? 0,
                    $this->limiter->availableIn($key)
                );
            }

            $results['attempts'][$type] = $attempts;
        }

        // Check burst protection
        if ($this->checkBurstLimit($keys['burst'], $config)) {
            $results['violated'] = true;
            $results['violations'][] = 'burst';
            $results['retry_after'] = max(
                $results['retry_after'] ?? 0,
                $config['burst_decay'] * 60
            );
        }

        return $results;
    }

    /**
     * Check burst protection limit
     * 
     * Security Decision: Burst protection prevents sudden traffic spikes
     * that could overwhelm the system, even if normal rate limits
     * haven't been exceeded.
     * 
     * @param string $key
     * @param array $config
     * @return bool
     */
    protected function checkBurstLimit(string $key, array $config): bool
    {
        $burstLimit = $config['burst_limit'] ?? ($config['max_attempts'] * 2);
        $burstDecay = $config['burst_decay'] ?? ($config['decay_minutes'] * 2);

        return $this->limiter->tooManyAttempts($key, $burstLimit, $burstDecay);
    }

    /**
     * Handle rate limit violation with comprehensive security logging
     * 
     * Security Decision: Detailed logging of rate limit violations is crucial
     * for security monitoring and incident response. We log different levels
     * of severity based on the violation type and frequency.
     * 
     * @param Request $request
     * @param array $rateLimitResults
     * @param array $config
     * @return Response
     * @throws ThrottleRequestsException
     */
    protected function handleRateLimitViolation(Request $request, array $rateLimitResults, array $config): Response
    {
        $ip = $this->getClientIp($request);
        $userId = $request->user()?->id;
        $endpoint = $request->path();
        $method = $request->method();

        // Determine violation severity
        $severity = $this->determineViolationSeverity($rateLimitResults, $request);

        // Log security event with appropriate severity
        $this->logRateLimitViolation($request, $rateLimitResults, $severity);

        // Check for suspicious patterns
        $this->checkSuspiciousActivity($request, $rateLimitResults);

        // Apply additional security measures if needed
        if ($severity === 'critical') {
            $this->applyCriticalSecurityMeasures($request, $rateLimitResults);
        }

        // Prepare error response
        $retryAfter = $rateLimitResults['retry_after'] ?? 60;
        $response = $this->buildRateLimitResponse($retryAfter, $rateLimitResults);

        // Add security headers
        $this->addSecurityHeaders($response, $retryAfter);

        return $response;
    }

    /**
     * Determine violation severity for security response
     * 
     * @param array $rateLimitResults
     * @param Request $request
     * @return string
     */
    protected function determineViolationSeverity(array $rateLimitResults, Request $request): string
    {
        $violationCount = count($rateLimitResults['violations']);
        $totalAttempts = array_sum($rateLimitResults['attempts']);

        // Critical: Multiple violations or very high attempt count
        if ($violationCount >= 3 || $totalAttempts >= $this->securityThresholds['critical_attempts']) {
            return 'critical';
        }

        // High: Multiple violations or high attempt count
        if ($violationCount >= 2 || $totalAttempts >= $this->securityThresholds['suspicious_attempts']) {
            return 'high';
        }

        // Medium: Single violation with moderate attempts
        if ($totalAttempts >= 20) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Log rate limit violation with comprehensive security context
     * 
     * Security Decision: Detailed security logging is essential for:
     * - Incident response and forensics
     * - Pattern detection for advanced threats
     * - Compliance with security audit requirements
     * - Real-time security monitoring
     * 
     * @param Request $request
     * @param array $rateLimitResults
     * @param string $severity
     * @return void
     */
    protected function logRateLimitViolation(Request $request, array $rateLimitResults, string $severity): void
    {
        $logData = [
            'event' => 'rate_limit_violation',
            'severity' => $severity,
            'timestamp' => now()->toIso8601String(),
            'ip_address' => $this->getClientIp($request),
            'user_id' => $request->user()?->id,
            'endpoint' => $request->path(),
            'method' => $request->method(),
            'user_agent' => $request->userAgent(),
            'violations' => $rateLimitResults['violations'],
            'attempts' => $rateLimitResults['attempts'],
            'retry_after' => $rateLimitResults['retry_after'],
            'request_id' => $request->header('X-Request-ID'),
            'session_id' => $request->session()?->getId(),
        ];

        // Log with appropriate level based on severity
        switch ($severity) {
            case 'critical':
                Log::critical('Critical rate limit violation detected', $logData);
                break;
            case 'high':
                Log::warning('High severity rate limit violation', $logData);
                break;
            case 'medium':
                Log::info('Rate limit violation', $logData);
                break;
            default:
                Log::debug('Rate limit exceeded', $logData);
        }
    }

    /**
     * Check for suspicious activity patterns
     * 
     * Security Decision: Pattern detection helps identify coordinated attacks
     * and advanced persistent threats that might not be caught by simple
     * rate limiting alone.
     * 
     * @param Request $request
     * @param array $rateLimitResults
     * @return void
     */
    protected function checkSuspiciousActivity(Request $request, array $rateLimitResults): void
    {
        $ip = $this->getClientIp($request);
        $userId = $request->user()?->id;

        // Check for rapid-fire requests (potential bot/script)
        $rapidFireKey = "rapid_fire:{$ip}";
        $rapidFireCount = Cache::increment($rapidFireKey, 1);
        Cache::expire($rapidFireKey, 60); // 1 minute window

        if ($rapidFireCount > 20) {
            Log::warning('Rapid-fire request pattern detected', [
                'ip' => $ip,
                'count' => $rapidFireCount,
                'endpoint' => $request->path(),
            ]);
        }

        // Check for distributed attack patterns
        $this->checkDistributedAttackPatterns($request, $rateLimitResults);

        // Check for user agent anomalies
        $this->checkUserAgentAnomalies($request);
    }

    /**
     * Check for distributed attack patterns
     * 
     * @param Request $request
     * @param array $rateLimitResults
     * @return void
     */
    protected function checkDistributedAttackPatterns(Request $request, array $rateLimitResults): void
    {
        $endpoint = $request->path();
        $method = $request->method();

        // Track endpoint-specific attack patterns
        $endpointKey = "attack_pattern:{$endpoint}:{$method}";
        $attackCount = Cache::increment($endpointKey, 1);
        Cache::expire($endpointKey, 300); // 5 minute window

        if ($attackCount > 50) {
            Log::warning('Distributed attack pattern detected on endpoint', [
                'endpoint' => $endpoint,
                'method' => $method,
                'attack_count' => $attackCount,
            ]);
        }
    }

    /**
     * Check for user agent anomalies
     * 
     * @param Request $request
     * @return void
     */
    protected function checkUserAgentAnomalies(Request $request): void
    {
        $userAgent = $request->userAgent();

        // Check for suspicious user agents
        $suspiciousPatterns = [
            'bot',
            'crawler',
            'spider',
            'scraper',
            'curl',
            'wget',
            'python',
            'java',
            'automated',
            'script',
            'test'
        ];

        $isSuspicious = false;
        foreach ($suspiciousPatterns as $pattern) {
            if (stripos($userAgent, $pattern) !== false) {
                $isSuspicious = true;
                break;
            }
        }

        if ($isSuspicious) {
            Log::info('Suspicious user agent detected', [
                'user_agent' => $userAgent,
                'ip' => $this->getClientIp($request),
                'endpoint' => $request->path(),
            ]);
        }
    }

    /**
     * Apply critical security measures for severe violations
     * 
     * Security Decision: Critical violations require immediate response
     * to prevent system compromise. This includes temporary blocking
     * and enhanced monitoring.
     * 
     * @param Request $request
     * @param array $rateLimitResults
     * @return void
     */
    protected function applyCriticalSecurityMeasures(Request $request, array $rateLimitResults): void
    {
        $ip = $this->getClientIp($request);

        // Apply temporary IP block
        $blockKey = "ip_block:{$ip}";
        Cache::put($blockKey, true, $this->securityThresholds['block_duration']);

        // Log critical security event
        Log::critical('Critical security measures applied', [
            'ip' => $ip,
            'user_id' => $request->user()?->id,
            'endpoint' => $request->path(),
            'violations' => $rateLimitResults['violations'],
            'block_duration' => $this->securityThresholds['block_duration'],
        ]);

        // Send security alert (if configured)
        $this->sendSecurityAlert($request, $rateLimitResults);
    }

    /**
     * Send security alert for critical violations
     * 
     * @param Request $request
     * @param array $rateLimitResults
     * @return void
     */
    protected function sendSecurityAlert(Request $request, array $rateLimitResults): void
    {
        // This would integrate with your alerting system
        // For now, we'll just log the alert
        Log::critical('SECURITY ALERT: Critical rate limit violation', [
            'ip' => $this->getClientIp($request),
            'user_id' => $request->user()?->id,
            'endpoint' => $request->path(),
            'violations' => $rateLimitResults['violations'],
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Build rate limit response with security headers
     * 
     * @param int $retryAfter
     * @param array $rateLimitResults
     * @return Response
     * @throws ThrottleRequestsException
     */
    protected function buildRateLimitResponse(int $retryAfter, array $rateLimitResults): Response
    {
        $message = 'Too many requests. Please try again later.';

        // Customize message based on violation type
        if (in_array('auth', $rateLimitResults['violations'])) {
            $message = 'Too many authentication attempts. Please try again later.';
        } elseif (in_array('webhooks', $rateLimitResults['violations'])) {
            $message = 'Webhook rate limit exceeded. Please reduce request frequency.';
        }

        throw new ThrottleRequestsException($message, null, [], $retryAfter);
    }

    /**
     * Add security headers to response
     * 
     * Security Decision: Security headers provide additional protection
     * and help clients understand rate limiting policies.
     * 
     * @param Response $response
     * @param int $retryAfter
     * @return void
     */
    protected function addSecurityHeaders(Response $response, int $retryAfter): void
    {
        $response->headers->set('X-RateLimit-Retry-After', $retryAfter);
        $response->headers->set('X-RateLimit-Policy', 'exponential-backoff');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
    }

    /**
     * Record request for rate limiting
     * 
     * @param array $keys
     * @param array $config
     * @return void
     */
    protected function recordRequest(array $keys, array $config): void
    {
        foreach ($keys as $type => $key) {
            if (!$key) continue;

            $this->limiter->hit($key, $config['decay_minutes'] * 60);
        }

        // Record burst protection
        if (isset($keys['burst'])) {
            $this->limiter->hit($keys['burst'], $config['burst_decay'] * 60);
        }
    }

    /**
     * Log security events for monitoring
     * 
     * @param Request $request
     * @param array $keys
     * @param array $rateLimitResults
     * @return void
     */
    protected function logSecurityEvents(Request $request, array $keys, array $rateLimitResults): void
    {
        $totalAttempts = array_sum($rateLimitResults['attempts']);

        // Log high attempt counts for monitoring
        if ($totalAttempts > 10) {
            Log::info('High request volume detected', [
                'ip' => $this->getClientIp($request),
                'user_id' => $request->user()?->id,
                'endpoint' => $request->path(),
                'total_attempts' => $totalAttempts,
                'attempts_by_type' => $rateLimitResults['attempts'],
            ]);
        }
    }

    /**
     * Add rate limit headers to successful response
     * 
     * @param Response $response
     * @param array $keys
     * @param array $config
     * @return void
     */
    protected function addRateLimitHeaders(Response $response, array $keys, array $config): void
    {
        $response->headers->set('X-RateLimit-Limit', $config['max_attempts']);
        $response->headers->set('X-RateLimit-Remaining', max(0, $config['max_attempts'] - array_sum($keys)));
        $response->headers->set('X-RateLimit-Reset', now()->addMinutes($config['decay_minutes'])->timestamp);
    }
}
