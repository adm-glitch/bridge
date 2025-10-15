<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Carbon\Carbon;

class AuthService
{
    private string $jwtSecret;
    private string $jwtAlgorithm;
    private int $tokenExpirationMinutes;

    public function __construct()
    {
        $this->jwtSecret = config('auth.jwt.secret', config('app.key'));
        $this->jwtAlgorithm = config('auth.jwt.algorithm', 'HS256');
        $this->tokenExpirationMinutes = config('auth.jwt.expiration', 60);
    }

    /**
     * Authenticate user credentials
     */
    public function authenticate(array $credentials, string $ip, string $userAgent): array
    {
        try {
            $email = $credentials['email'];
            $password = $credentials['password'];
            $rememberMe = $credentials['remember_me'] ?? false;

            // Find user by email
            $user = User::where('email', $email)->first();

            if (!$user) {
                return [
                    'success' => false,
                    'message' => 'Invalid credentials',
                    'error_code' => 'INVALID_CREDENTIALS',
                    'reason' => 'user_not_found'
                ];
            }

            // Check if user is active
            if (!$user->is_active) {
                return [
                    'success' => false,
                    'message' => 'Account is deactivated',
                    'error_code' => 'ACCOUNT_DEACTIVATED',
                    'reason' => 'account_inactive'
                ];
            }

            // Verify password
            if (!Hash::check($password, $user->password)) {
                return [
                    'success' => false,
                    'message' => 'Invalid credentials',
                    'error_code' => 'INVALID_CREDENTIALS',
                    'reason' => 'invalid_password'
                ];
            }

            // Check for suspicious login patterns
            if ($this->isSuspiciousLogin($user, $ip)) {
                Log::warning('Suspicious login attempt detected', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'ip' => $ip,
                    'user_agent' => $userAgent
                ]);

                return [
                    'success' => false,
                    'message' => 'Login blocked due to security policy',
                    'error_code' => 'SUSPICIOUS_LOGIN',
                    'reason' => 'suspicious_activity'
                ];
            }

            // Generate JWT token
            $token = $this->generateJwtToken($user, $ip, $userAgent);

            // Update user login information
            $user->update([
                'last_login_at' => now(),
                'last_login_ip' => $ip,
                'login_count' => $user->login_count + 1
            ]);

            // Get user abilities
            $abilities = $this->getUserAbilities($user);

            return [
                'success' => true,
                'user' => $user,
                'token' => $token,
                'abilities' => $abilities
            ];
        } catch (\Exception $e) {
            Log::error('Authentication error', [
                'email' => $credentials['email'] ?? 'unknown',
                'ip' => $ip,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'Authentication service error',
                'error_code' => 'AUTH_SERVICE_ERROR',
                'reason' => 'service_error'
            ];
        }
    }

    /**
     * Generate JWT token for user
     */
    public function generateJwtToken(User $user, string $ip, string $userAgent): string
    {
        $now = time();
        $expiration = $now + ($this->tokenExpirationMinutes * 60);

        $payload = [
            'iss' => config('app.url'), // Issuer
            'aud' => config('app.name'), // Audience
            'iat' => $now, // Issued at
            'exp' => $expiration, // Expiration
            'nbf' => $now, // Not before
            'jti' => Str::uuid()->toString(), // JWT ID
            'sub' => $user->id, // Subject (user ID)
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => $ip,
            'user_agent' => hash('sha256', $userAgent), // Hash user agent for privacy
            'abilities' => $this->getUserAbilities($user)
        ];

        $token = JWT::encode($payload, $this->jwtSecret, $this->jwtAlgorithm);

        // Store token in cache for validation
        Cache::put(
            "jwt:{$user->id}:{$payload['jti']}",
            [
                'user_id' => $user->id,
                'ip' => $ip,
                'created_at' => $now,
                'expires_at' => $expiration
            ],
            $this->tokenExpirationMinutes * 60
        );

        return $token;
    }

    /**
     * Validate JWT token
     */
    public function validateToken(string $token): array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->jwtSecret, $this->jwtAlgorithm));
            $payload = (array) $decoded;

            // Check if token is in cache (not blacklisted)
            $cacheKey = "jwt:{$payload['user_id']}:{$payload['jti']}";
            $cachedToken = Cache::get($cacheKey);

            if (!$cachedToken) {
                return [
                    'valid' => false,
                    'message' => 'Token has been invalidated',
                    'error_code' => 'TOKEN_INVALIDATED'
                ];
            }

            // Check IP address if provided
            $currentIp = request()->ip();
            if (isset($payload['ip']) && $payload['ip'] !== $currentIp) {
                Log::warning('Token used from different IP', [
                    'user_id' => $payload['user_id'],
                    'token_ip' => $payload['ip'],
                    'current_ip' => $currentIp
                ]);
            }

            return [
                'valid' => true,
                'user_id' => $payload['user_id'],
                'expires_at' => Carbon::createFromTimestamp($payload['exp'])->toIso8601String(),
                'abilities' => $payload['abilities'] ?? []
            ];
        } catch (\Firebase\JWT\ExpiredException $e) {
            return [
                'valid' => false,
                'message' => 'Token has expired',
                'error_code' => 'TOKEN_EXPIRED'
            ];
        } catch (\Firebase\JWT\SignatureInvalidException $e) {
            return [
                'valid' => false,
                'message' => 'Invalid token signature',
                'error_code' => 'INVALID_SIGNATURE'
            ];
        } catch (\Exception $e) {
            Log::error('Token validation error', [
                'error' => $e->getMessage(),
                'token_preview' => substr($token, 0, 20) . '...'
            ]);

            return [
                'valid' => false,
                'message' => 'Token validation failed',
                'error_code' => 'TOKEN_VALIDATION_ERROR'
            ];
        }
    }

    /**
     * Refresh JWT token
     */
    public function refreshToken(string $token, string $ip, string $userAgent): array
    {
        $validation = $this->validateToken($token);

        if (!$validation['valid']) {
            return [
                'success' => false,
                'message' => $validation['message'],
                'error_code' => $validation['error_code'],
                'reason' => 'invalid_token'
            ];
        }

        try {
            $user = User::find($validation['user_id']);

            if (!$user || !$user->is_active) {
                return [
                    'success' => false,
                    'message' => 'User not found or inactive',
                    'error_code' => 'USER_NOT_FOUND',
                    'reason' => 'user_invalid'
                ];
            }

            // Invalidate old token
            $this->invalidateToken($token);

            // Generate new token
            $newToken = $this->generateJwtToken($user, $ip, $userAgent);

            return [
                'success' => true,
                'user' => $user,
                'token' => $newToken
            ];
        } catch (\Exception $e) {
            Log::error('Token refresh error', [
                'user_id' => $validation['user_id'] ?? 'unknown',
                'ip' => $ip,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Token refresh failed',
                'error_code' => 'REFRESH_ERROR',
                'reason' => 'service_error'
            ];
        }
    }

    /**
     * Invalidate JWT token
     */
    public function invalidateToken(string $token): bool
    {
        try {
            $decoded = JWT::decode($token, new Key($this->jwtSecret, $this->jwtAlgorithm));
            $payload = (array) $decoded;

            // Remove from cache
            $cacheKey = "jwt:{$payload['user_id']}:{$payload['jti']}";
            Cache::forget($cacheKey);

            // Add to blacklist
            Cache::put(
                "jwt:blacklist:{$payload['jti']}",
                true,
                $this->tokenExpirationMinutes * 60
            );

            return true;
        } catch (\Exception $e) {
            Log::error('Token invalidation error', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get user abilities/permissions
     */
    public function getUserAbilities(User $user): array
    {
        return Cache::remember(
            "user:{$user->id}:abilities",
            300, // 5 minutes
            function () use ($user) {
                $abilities = [];

                // Get role-based abilities
                $roles = $user->getRoleNames();
                foreach ($roles as $role) {
                    $abilities = array_merge($abilities, $this->getRoleAbilities($role));
                }

                // Get direct permissions
                $permissions = $user->getAllPermissions();
                foreach ($permissions as $permission) {
                    $abilities[] = $permission->name;
                }

                return array_unique($abilities);
            }
        );
    }

    /**
     * Get abilities for a specific role
     */
    private function getRoleAbilities(string $role): array
    {
        $roleAbilities = [
            'admin' => [
                'conversations:read',
                'conversations:write',
                'insights:read',
                'insights:write',
                'admin:read',
                'admin:write',
                'lgpd:read',
                'lgpd:write'
            ],
            'manager' => [
                'conversations:read',
                'conversations:write',
                'insights:read',
                'lgpd:read'
            ],
            'agent' => [
                'conversations:read',
                'conversations:write',
                'insights:read'
            ],
            'user' => [
                'conversations:read'
            ]
        ];

        return $roleAbilities[$role] ?? [];
    }

    /**
     * Check for suspicious login patterns
     */
    private function isSuspiciousLogin(User $user, string $ip): bool
    {
        // Check for rapid login attempts
        $recentAttempts = Cache::get("login_attempts:{$ip}", 0);
        if ($recentAttempts > 10) {
            return true;
        }

        // Check for unusual IP patterns
        $lastLoginIp = $user->last_login_ip;
        if ($lastLoginIp && $lastLoginIp !== $ip) {
            // Check if IP is from different country/region
            // This would require IP geolocation service
            // For now, just log the change
            Log::info('User login from different IP', [
                'user_id' => $user->id,
                'previous_ip' => $lastLoginIp,
                'current_ip' => $ip
            ]);
        }

        return false;
    }

    /**
     * Get user from token
     */
    public function getUserFromToken(string $token): ?User
    {
        $validation = $this->validateToken($token);

        if (!$validation['valid']) {
            return null;
        }

        return User::find($validation['user_id']);
    }
}
