<?php

namespace App\Services;

use App\Exceptions\ConsentException;
use App\Models\ConsentRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * Enhanced Consent Service with LGPD compliance, retry logic, caching, and security measures
 * 
 * Features:
 * - LGPD compliance management
 * - Consent lifecycle management
 * - Audit trail for all operations
 * - Data retention enforcement
 * - Security measures and validation
 * - Performance optimization with caching
 * - Rate limiting protection
 * - Comprehensive error handling
 * 
 * @package App\Services
 * @author Bridge Service
 * @version 2.1
 */
class ConsentService
{
    private array $consentTypes;
    private array $retentionPolicies;
    private int $maxRequestsPerMinute = 30; // Lower limit for consent operations
    private string $rateLimitKey = 'consent_operations';
    private int $circuitBreakerThreshold = 3;
    private int $circuitBreakerTimeout = 300; // 5 minutes

    public function __construct()
    {
        $this->consentTypes = config('lgpd.consent_types', []);
        $this->retentionPolicies = config('lgpd.retention_policies', []);
    }

    /**
     * Grant consent for a contact
     */
    public function grantConsent(
        int $contactId,
        string $consentType,
        Request $request,
        ?int $chatwootContactId = null
    ): ConsentRecord {
        $this->validateRateLimit();
        $this->checkCircuitBreaker();
        $this->validateConsentType($consentType);

        return DB::transaction(function () use ($contactId, $consentType, $request, $chatwootContactId) {
            // Check if consent already exists
            $existingConsent = $this->getActiveConsent($contactId, $consentType);
            if ($existingConsent) {
                throw ConsentException::alreadyExists($contactId, $consentType);
            }

            // Create consent record
            $consent = ConsentRecord::create([
                'contact_id' => $contactId,
                'chatwoot_contact_id' => $chatwootContactId,
                'consent_type' => $consentType,
                'status' => 'granted',
                'granted_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'consent_text' => $this->getConsentText($consentType),
                'consent_version' => config('lgpd.consent_version', '1.0'),
            ]);

            // Create audit log
            $this->auditConsentOperation('grant', $contactId, $consentType, $request);

            // Invalidate cache
            $this->invalidateConsentCache($contactId, $consentType);

            Log::info('Consent granted', [
                'contact_id' => $contactId,
                'consent_type' => $consentType,
                'consent_id' => $consent->id,
            ]);

            return $consent;
        });
    }

    /**
     * Withdraw consent for a contact
     */
    public function withdrawConsent(
        int $contactId,
        string $consentType,
        Request $request,
        ?string $reason = null
    ): ConsentRecord {
        $this->validateRateLimit();
        $this->checkCircuitBreaker();

        return DB::transaction(function () use ($contactId, $consentType, $request, $reason) {
            $consent = $this->getActiveConsent($contactId, $consentType);
            if (!$consent) {
                throw ConsentException::notFound($contactId, $consentType);
            }

            // Update consent record
            $consent->update([
                'status' => 'withdrawn',
                'withdrawn_at' => now(),
                'withdrawal_reason' => $reason,
            ]);

            // Create audit log
            $this->auditConsentOperation('withdraw', $contactId, $consentType, $request, $reason);

            // Invalidate cache
            $this->invalidateConsentCache($contactId, $consentType);

            Log::info('Consent withdrawn', [
                'contact_id' => $contactId,
                'consent_type' => $consentType,
                'consent_id' => $consent->id,
                'reason' => $reason,
            ]);

            return $consent;
        });
    }

    /**
     * Check if contact has valid consent
     */
    public function hasValidConsent(int $contactId, string $consentType): bool
    {
        $cacheKey = "consent:valid:{$contactId}:{$consentType}";
        $ttl = config('lgpd.cache_ttl.consent_validity', 300);

        return Cache::remember($cacheKey, $ttl, function () use ($contactId, $consentType) {
            $consent = ConsentRecord::where('contact_id', $contactId)
                ->where('consent_type', $consentType)
                ->where('status', 'granted')
                ->whereNull('withdrawn_at')
                ->where('granted_at', '>', now()->subDays($this->getConsentValidityDays($consentType)))
                ->first();

            return $consent !== null;
        });
    }

    /**
     * Get active consent for a contact
     */
    public function getActiveConsent(int $contactId, string $consentType): ?ConsentRecord
    {
        $cacheKey = "consent:active:{$contactId}:{$consentType}";
        $ttl = config('lgpd.cache_ttl.consent_active', 300);

        return Cache::remember($cacheKey, $ttl, function () use ($contactId, $consentType) {
            return ConsentRecord::where('contact_id', $contactId)
                ->where('consent_type', $consentType)
                ->where('status', 'granted')
                ->whereNull('withdrawn_at')
                ->first();
        });
    }

    /**
     * Get all consents for a contact
     */
    public function getContactConsents(int $contactId): array
    {
        $cacheKey = "consent:contact:{$contactId}";
        $ttl = config('lgpd.cache_ttl.contact_consents', 600);

        return Cache::remember($cacheKey, $ttl, function () use ($contactId) {
            return ConsentRecord::where('contact_id', $contactId)
                ->orderBy('created_at', 'desc')
                ->get()
                ->toArray();
        });
    }

    /**
     * Get consent by ID with caching
     */
    public function getConsentById(int $consentId): ConsentRecord
    {
        $cacheKey = "consent:id:{$consentId}";
        $ttl = config('lgpd.cache_ttl.consent_by_id', 600);

        $consent = Cache::remember($cacheKey, $ttl, function () use ($consentId) {
            return ConsentRecord::find($consentId);
        });

        if (!$consent) {
            throw ConsentException::notFound(0, 'unknown');
        }

        return $consent;
    }

    /**
     * Update consent with audit trail
     */
    public function updateConsent(
        int $consentId,
        array $data,
        Request $request
    ): ConsentRecord {
        $this->validateRateLimit();
        $this->checkCircuitBreaker();

        return DB::transaction(function () use ($consentId, $data, $request) {
            $consent = $this->getConsentById($consentId);

            // Store original data for audit
            $originalData = $consent->toArray();

            // Update consent
            $consent->update($data);

            // Create audit log
            $this->auditConsentOperation('update', $consent->contact_id, $consent->consent_type, $request, null, [
                'original_data' => $originalData,
                'new_data' => $data,
            ]);

            // Invalidate cache
            $this->invalidateConsentCache($consent->contact_id, $consent->consent_type);

            Log::info('Consent updated', [
                'consent_id' => $consentId,
                'contact_id' => $consent->contact_id,
                'changes' => $data,
            ]);

            return $consent;
        });
    }

    /**
     * Delete consent (soft delete with audit trail)
     */
    public function deleteConsent(
        int $consentId,
        Request $request,
        ?string $reason = null
    ): bool {
        $this->validateRateLimit();
        $this->checkCircuitBreaker();

        return DB::transaction(function () use ($consentId, $request, $reason) {
            $consent = $this->getConsentById($consentId);

            // Create audit log before deletion
            $this->auditConsentOperation('delete', $consent->contact_id, $consent->consent_type, $request, $reason);

            // Soft delete
            $consent->delete();

            // Invalidate cache
            $this->invalidateConsentCache($consent->contact_id, $consent->consent_type);

            Log::info('Consent deleted', [
                'consent_id' => $consentId,
                'contact_id' => $consent->contact_id,
                'reason' => $reason,
            ]);

            return true;
        });
    }

    /**
     * Get consent statistics for monitoring
     */
    public function getConsentStats(): array
    {
        $cacheKey = 'consent:stats';
        $ttl = config('lgpd.cache_ttl.consent_stats', 3600);

        return Cache::remember($cacheKey, $ttl, function () {
            $stats = [];

            foreach ($this->consentTypes as $type) {
                $stats[$type] = [
                    'total' => ConsentRecord::where('consent_type', $type)->count(),
                    'granted' => ConsentRecord::where('consent_type', $type)
                        ->where('status', 'granted')
                        ->whereNull('withdrawn_at')
                        ->count(),
                    'withdrawn' => ConsentRecord::where('consent_type', $type)
                        ->where('status', 'withdrawn')
                        ->count(),
                    'expired' => ConsentRecord::where('consent_type', $type)
                        ->where('granted_at', '<', now()->subDays($this->getConsentValidityDays($type)))
                        ->count(),
                ];
            }

            return $stats;
        });
    }

    /**
     * Enforce data retention policies
     */
    public function enforceDataRetention(): array
    {
        $processed = [];

        foreach ($this->retentionPolicies as $consentType => $retentionDays) {
            $cutoffDate = now()->subDays($retentionDays);

            $expiredConsents = ConsentRecord::where('consent_type', $consentType)
                ->where('granted_at', '<', $cutoffDate)
                ->where('status', 'granted')
                ->get();

            foreach ($expiredConsents as $consent) {
                // Mark as expired
                $consent->update([
                    'status' => 'expired',
                    'expired_at' => now(),
                ]);

                $processed[] = $consent->id;
            }
        }

        Log::info('Data retention enforced', [
            'processed_consents' => count($processed),
            'consent_ids' => $processed,
        ]);

        return $processed;
    }

    /**
     * Validate consent type
     */
    private function validateConsentType(string $consentType): void
    {
        if (!in_array($consentType, $this->consentTypes)) {
            throw ConsentException::validationError(
                "Invalid consent type: {$consentType}",
                ['consent_type' => $consentType, 'valid_types' => $this->consentTypes]
            );
        }
    }

    /**
     * Get consent text for a type
     */
    private function getConsentText(string $consentType): string
    {
        return config("lgpd.consent_texts.{$consentType}", "Consent for {$consentType}");
    }

    /**
     * Get consent validity days for a type
     */
    private function getConsentValidityDays(string $consentType): int
    {
        return config("lgpd.consent_validity_days.{$consentType}", 365);
    }

    /**
     * Create audit log for consent operation
     */
    private function auditConsentOperation(
        string $operation,
        int $contactId,
        string $consentType,
        Request $request,
        ?string $reason = null,
        ?array $metadata = null
    ): void {
        try {
            DB::table('audit_logs')->insert([
                'user_id' => auth()->id(),
                'action' => $operation,
                'model' => 'ConsentRecord',
                'model_id' => null, // Will be updated after consent creation
                'changes' => json_encode([
                    'consent_type' => $consentType,
                    'reason' => $reason,
                    'metadata' => $metadata,
                ]),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create consent audit log', [
                'operation' => $operation,
                'contact_id' => $contactId,
                'error' => $e->getMessage(),
            ]);
            throw ConsentException::auditError($operation, $contactId);
        }
    }

    /**
     * Validate rate limiting
     */
    private function validateRateLimit(): void
    {
        if (RateLimiter::tooManyAttempts($this->rateLimitKey, $this->maxRequestsPerMinute)) {
            $seconds = RateLimiter::availableIn($this->rateLimitKey);

            Log::warning('Consent service rate limit exceeded', [
                'retry_after' => $seconds,
                'limit' => $this->maxRequestsPerMinute,
            ]);

            throw new ConsentException(
                "Rate limit exceeded. Try again in {$seconds} seconds.",
                429,
                null,
                ['retry_after' => $seconds],
                'rate_limit_check',
                null,
                null,
                'CONSENT_RATE_LIMIT_EXCEEDED'
            );
        }

        RateLimiter::hit($this->rateLimitKey, 60);
    }

    /**
     * Check circuit breaker status
     */
    private function checkCircuitBreaker(): void
    {
        $failures = Cache::get('consent_circuit_breaker_failures', 0);
        $lastFailure = Cache::get('consent_circuit_breaker_last_failure', 0);

        if ($failures >= $this->circuitBreakerThreshold) {
            $timeSinceLastFailure = time() - $lastFailure;

            if ($timeSinceLastFailure < $this->circuitBreakerTimeout) {
                $remainingTime = $this->circuitBreakerTimeout - $timeSinceLastFailure;

                Log::warning('Consent service circuit breaker is open', [
                    'failures' => $failures,
                    'remaining_time' => $remainingTime,
                ]);

                throw new ConsentException(
                    "Circuit breaker is open. Try again in {$remainingTime} seconds.",
                    503,
                    null,
                    ['circuit_breaker_open' => true, 'remaining_time' => $remainingTime],
                    'circuit_breaker_check',
                    null,
                    null,
                    'CONSENT_SERVICE_UNAVAILABLE'
                );
            }
        }
    }

    /**
     * Record circuit breaker failure
     */
    private function recordCircuitBreakerFailure(): void
    {
        $failures = Cache::get('consent_circuit_breaker_failures', 0) + 1;
        Cache::put('consent_circuit_breaker_failures', $failures, 3600);
        Cache::put('consent_circuit_breaker_last_failure', time(), 3600);
    }

    /**
     * Reset circuit breaker on success
     */
    private function resetCircuitBreaker(): void
    {
        Cache::forget('consent_circuit_breaker_failures');
        Cache::forget('consent_circuit_breaker_last_failure');
    }

    /**
     * Invalidate consent-related caches
     */
    private function invalidateConsentCache(int $contactId, string $consentType): void
    {
        $cacheKeys = [
            "consent:valid:{$contactId}:{$consentType}",
            "consent:active:{$contactId}:{$consentType}",
            "consent:contact:{$contactId}",
        ];

        foreach ($cacheKeys as $key) {
            Cache::forget($key);
        }

        // Also clear tag-based caches if using Redis
        if (config('cache.default') === 'redis') {
            Cache::tags(['consent', 'contact', "contact:{$contactId}"])->flush();
        }
    }

    /**
     * Get service statistics for monitoring
     */
    public function getStats(): array
    {
        return [
            'rate_limit_remaining' => RateLimiter::remaining($this->rateLimitKey, $this->maxRequestsPerMinute),
            'circuit_breaker_failures' => Cache::get('consent_circuit_breaker_failures', 0),
            'circuit_breaker_last_failure' => Cache::get('consent_circuit_breaker_last_failure', 0),
            'consent_types' => $this->consentTypes,
            'retention_policies' => $this->retentionPolicies,
        ];
    }
}
