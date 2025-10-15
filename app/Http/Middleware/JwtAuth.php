<?php

namespace App\Http\Middleware;

use App\Services\AuthService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class JwtAuth
{
    private AuthService $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
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
            $validation = $this->authService->validateToken($token);

            if (!$validation['valid']) {
                Log::warning('Invalid JWT token', [
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'error_code' => $validation['error_code'] ?? 'unknown'
                ]);

                return response()->json([
                    'success' => false,
                    'error' => $validation['message'],
                    'error_code' => $validation['error_code'],
                    'timestamp' => now()->toIso8601String()
                ], 401);
            }

            // Get user from token
            $user = $this->authService->getUserFromToken($token);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'error' => 'User not found',
                    'error_code' => 'USER_NOT_FOUND',
                    'timestamp' => now()->toIso8601String()
                ], 401);
            }

            // Check if user is still active
            if (!$user->is_active) {
                return response()->json([
                    'success' => false,
                    'error' => 'Account is deactivated',
                    'error_code' => 'ACCOUNT_DEACTIVATED',
                    'timestamp' => now()->toIso8601String()
                ], 401);
            }

            // Set authenticated user
            $request->setUserResolver(function () use ($user) {
                return $user;
            });

            // Add user abilities to request
            $request->merge(['user_abilities' => $validation['abilities'] ?? []]);

            return $next($request);
        } catch (\Exception $e) {
            Log::error('JWT authentication error', [
                'ip' => $request->ip(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Authentication service error',
                'error_code' => 'AUTH_SERVICE_ERROR',
                'timestamp' => now()->toIso8601String()
            ], 500);
        }
    }
}
