<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\LoginRequest;
use App\Http\Requests\Api\Auth\RefreshTokenRequest;
use App\Services\AuthService;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private AuthService $authService;
    private AuditService $auditService;

    public function __construct(AuthService $authService, AuditService $auditService)
    {
        $this->authService = $authService;
        $this->auditService = $auditService;
    }

    /**
     * Authenticate user and generate JWT token
     * 
     * @param LoginRequest $request
     * @return JsonResponse
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $ip = $request->ip();
        $userAgent = $request->userAgent();

        // Rate limiting: 5 requests per minute per IP
        $rateLimitKey = "auth:login:{$ip}";
        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);

            Log::warning('Login rate limit exceeded', [
                'ip' => $ip,
                'email' => $request->input('email'),
                'retry_after' => $seconds
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Too many login attempts',
                'error_code' => 'RATE_LIMIT_EXCEEDED',
                'retry_after' => $seconds,
                'limit' => 5,
                'window' => '1 minute'
            ], 429);
        }

        try {
            // Validate credentials
            $credentials = $request->validated();

            // Attempt authentication
            $result = $this->authService->authenticate($credentials, $ip, $userAgent);

            if (!$result['success']) {
                // Increment rate limiter on failed attempt
                RateLimiter::hit($rateLimitKey, 60);

                Log::warning('Login failed', [
                    'ip' => $ip,
                    'email' => $credentials['email'],
                    'reason' => $result['reason']
                ]);

                return response()->json([
                    'success' => false,
                    'error' => $result['message'],
                    'error_code' => $result['error_code'],
                    'timestamp' => now()->toIso8601String()
                ], 401);
            }

            // Clear rate limiter on successful login
            RateLimiter::clear($rateLimitKey);

            // Log successful authentication
            $this->auditService->logAuthentication($result['user'], 'login', $ip, $userAgent);

            Log::info('User authenticated successfully', [
                'user_id' => $result['user']->id,
                'email' => $result['user']->email,
                'ip' => $ip
            ]);

            return response()->json([
                'success' => true,
                'access_token' => $result['token'],
                'token_type' => 'Bearer',
                'expires_in' => 3600, // 60 minutes
                'expires_at' => now()->addMinutes(60)->toIso8601String(),
                'abilities' => $result['abilities'],
                'user' => [
                    'id' => $result['user']->id,
                    'name' => $result['user']->name,
                    'email' => $result['user']->email,
                    'role' => $result['user']->getRoleNames()->first() ?? 'user'
                ],
                'response_time_ms' => round((microtime(true) - LARAVEL_START) * 1000, 2)
            ], 200);
        } catch (ValidationException $e) {
            RateLimiter::hit($rateLimitKey, 60);

            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'error_code' => 'VALIDATION_ERROR',
                'details' => $e->errors(),
                'timestamp' => now()->toIso8601String()
            ], 422);
        } catch (\Exception $e) {
            RateLimiter::hit($rateLimitKey, 60);

            Log::error('Login authentication error', [
                'ip' => $ip,
                'email' => $request->input('email'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Authentication service unavailable',
                'error_code' => 'AUTH_SERVICE_ERROR',
                'timestamp' => now()->toIso8601String()
            ], 500);
        }
    }

    /**
     * Refresh JWT token
     * 
     * @param RefreshTokenRequest $request
     * @return JsonResponse
     */
    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        $ip = $request->ip();
        $userAgent = $request->userAgent();

        // Rate limiting: 5 requests per minute per IP
        $rateLimitKey = "auth:refresh:{$ip}";
        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);

            return response()->json([
                'success' => false,
                'error' => 'Too many refresh attempts',
                'error_code' => 'RATE_LIMIT_EXCEEDED',
                'retry_after' => $seconds
            ], 429);
        }

        try {
            $token = $request->bearerToken();

            if (!$token) {
                RateLimiter::hit($rateLimitKey, 60);

                return response()->json([
                    'success' => false,
                    'error' => 'Token not provided',
                    'error_code' => 'TOKEN_MISSING',
                    'timestamp' => now()->toIso8601String()
                ], 401);
            }

            $result = $this->authService->refreshToken($token, $ip, $userAgent);

            if (!$result['success']) {
                RateLimiter::hit($rateLimitKey, 60);

                Log::warning('Token refresh failed', [
                    'ip' => $ip,
                    'reason' => $result['reason']
                ]);

                return response()->json([
                    'success' => false,
                    'error' => $result['message'],
                    'error_code' => $result['error_code'],
                    'timestamp' => now()->toIso8601String()
                ], 401);
            }

            // Clear rate limiter on successful refresh
            RateLimiter::clear($rateLimitKey);

            // Log token refresh
            $this->auditService->logAuthentication($result['user'], 'token_refresh', $ip, $userAgent);

            return response()->json([
                'success' => true,
                'access_token' => $result['token'],
                'expires_in' => 3600,
                'expires_at' => now()->addMinutes(60)->toIso8601String(),
                'response_time_ms' => round((microtime(true) - LARAVEL_START) * 1000, 2)
            ], 200);
        } catch (\Exception $e) {
            RateLimiter::hit($rateLimitKey, 60);

            Log::error('Token refresh error', [
                'ip' => $ip,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Token refresh service unavailable',
                'error_code' => 'REFRESH_SERVICE_ERROR',
                'timestamp' => now()->toIso8601String()
            ], 500);
        }
    }

    /**
     * Logout user and invalidate token
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function logout(Request $request): JsonResponse
    {
        $ip = $request->ip();
        $userAgent = $request->userAgent();
        $user = $request->user();

        try {
            $token = $request->bearerToken();

            if ($token) {
                // Invalidate token
                $this->authService->invalidateToken($token);

                // Log logout
                if ($user) {
                    $this->auditService->logAuthentication($user, 'logout', $ip, $userAgent);
                }
            }

            // Clear any cached user data
            if ($user) {
                Cache::forget("user:{$user->id}:permissions");
                Cache::forget("user:{$user->id}:abilities");
            }

            Log::info('User logged out successfully', [
                'user_id' => $user?->id,
                'ip' => $ip
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Logged out successfully',
                'timestamp' => now()->toIso8601String()
            ], 200);
        } catch (\Exception $e) {
            Log::error('Logout error', [
                'user_id' => $user?->id,
                'ip' => $ip,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Logout service unavailable',
                'error_code' => 'LOGOUT_SERVICE_ERROR',
                'timestamp' => now()->toIso8601String()
            ], 500);
        }
    }

    /**
     * Get current user information
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthenticated',
                'error_code' => 'UNAUTHENTICATED',
                'timestamp' => now()->toIso8601String()
            ], 401);
        }

        try {
            // Get user abilities from cache or generate
            $abilities = Cache::remember(
                "user:{$user->id}:abilities",
                300, // 5 minutes
                fn() => $this->authService->getUserAbilities($user)
            );

            return response()->json([
                'success' => true,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->getRoleNames()->first() ?? 'user',
                    'abilities' => $abilities,
                    'last_login_at' => $user->last_login_at?->toIso8601String(),
                    'created_at' => $user->created_at->toIso8601String()
                ],
                'response_time_ms' => round((microtime(true) - LARAVEL_START) * 1000, 2)
            ], 200);
        } catch (\Exception $e) {
            Log::error('Get user info error', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'User service unavailable',
                'error_code' => 'USER_SERVICE_ERROR',
                'timestamp' => now()->toIso8601String()
            ], 500);
        }
    }

    /**
     * Check token validity
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function validateToken(Request $request): JsonResponse
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json([
                'success' => false,
                'error' => 'Token not provided',
                'error_code' => 'TOKEN_MISSING',
                'timestamp' => now()->toIso8601String()
            ], 401);
        }

        try {
            $result = $this->authService->validateToken($token);

            if (!$result['valid']) {
                return response()->json([
                    'success' => false,
                    'error' => $result['message'],
                    'error_code' => $result['error_code'],
                    'timestamp' => now()->toIso8601String()
                ], 401);
            }

            return response()->json([
                'success' => true,
                'valid' => true,
                'expires_at' => $result['expires_at'],
                'user_id' => $result['user_id'],
                'timestamp' => now()->toIso8601String()
            ], 200);
        } catch (\Exception $e) {
            Log::error('Token validation error', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Token validation service unavailable',
                'error_code' => 'VALIDATION_SERVICE_ERROR',
                'timestamp' => now()->toIso8601String()
            ], 500);
        }
    }
}
