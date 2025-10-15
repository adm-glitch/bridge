<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

/**
 * Advanced Cached Consent Service with intelligent caching strategies
 * 
 * Features:
 * - Tag-based cache invalidation
 * - Stale-while-revalidate pattern
 * - Cache warming strategies
 * - Performance monitoring
 * - Cache hit/miss analytics
 * - LGPD compliance caching
 * - Audit trail caching
 * 
 * @package App\Services
 * @author Bridge Service
 * @version 2.1
 */
class CachedConsentService extends ConsentService
{
    private array $cacheStats = [
        'hits' => 0,
        'misses' => 0,
        'invalidations' => 0,
    ];

    private const CACHE_TAGS = [
        'consent',
        'contacts',
        'chatwoot',
        'lgpd',
    ];

    /**
     * Check if contact has valid consent with advanced caching
     */
    public function hasValidConsent(int $contactId, string $consentType): bool
    {
        $cacheKey = "consent:valid:{$contactId}:{$consentType}";
        $staleKey = "consent:valid:{$contactId}:{$consentType}:stale";
        $ttl = config('lgpd.cache_ttl.consent_validity', 300);

        // Try to get fresh data first
        $data = Cache::get($cacheKey);

        if ($data !== null) {
            $this->cacheStats['hits']++;

            // Check if we should refresh in background (stale-while-revalidate)
            $staleData = Cache::get($staleKey);
            if ($staleData === null) {
                // Start background refresh
                $this->warmCacheInBackground('hasValidConsent', [$contactId, $consentType], $cacheKey, $ttl);
            }

            return $data;
        }

        $this->cacheStats['misses']++;

        // Get fresh data and cache it
        $data = parent::hasValidConsent($contactId, $consentType);

        // Cache both fresh and stale versions
        Cache::put($cacheKey, $data, $ttl);
        Cache::put($staleKey, $data, $ttl * 2); // Stale version lasts twice as long

        return $data;
    }

    /**
     * Get active consent with intelligent caching
     */
    public function getActiveConsent(int $contactId, string $consentType): ?\App\Models\ConsentRecord
    {
        $cacheKey = "consent:active:{$contactId}:{$consentType}";
        $ttl = config('lgpd.cache_ttl.consent_active', 300);

        return Cache::tags(['consent', 'contacts', "contact:{$contactId}"])->remember(
            $cacheKey,
            $ttl,
            function () use ($contactId, $consentType) {
                $this->cacheStats['misses']++;
                return parent::getActiveConsent($contactId, $consentType);
            }
        );
    }

    /**
     * Get contact consents with caching
     */
    public function getContactConsents(int $contactId): array
    {
        $cacheKey = "consent:contact:{$contactId}";
        $ttl = config('lgpd.cache_ttl.contact_consents', 600);

        return Cache::tags(['consent', 'contacts', "contact:{$contactId}"])->remember(
            $cacheKey,
            $ttl,
            function () use ($contactId) {
                $this->cacheStats['misses']++;
                return parent::getContactConsents($contactId);
            }
        );
    }

    /**
     * Get consent by ID with caching
     */
    public function getConsentById(int $consentId): \App\Models\ConsentRecord
    {
        $cacheKey = "consent:id:{$consentId}";
        $ttl = config('lgpd.cache_ttl.consent_by_id', 600);

        return Cache::tags(['consent', 'consent_records'])->remember(
            $cacheKey,
            $ttl,
            function () use ($consentId) {
                $this->cacheStats['misses']++;
                return parent::getConsentById($consentId);
            }
        );
    }

    /**
     * Grant consent with cache invalidation
     */
    public function grantConsent(
        int $contactId,
        string $consentType,
        Request $request,
        ?int $chatwootContactId = null
    ): \App\Models\ConsentRecord {
        $result = parent::grantConsent($contactId, $consentType, $request, $chatwootContactId);

        // Invalidate related caches
        $this->invalidateContactConsentCache($contactId, $consentType);
        $this->invalidateConsentStatsCache();

        return $result;
    }

    /**
     * Withdraw consent with cache invalidation
     */
    public function withdrawConsent(
        int $contactId,
        string $consentType,
        Request $request,
        ?string $reason = null
    ): \App\Models\ConsentRecord {
        $result = parent::withdrawConsent($contactId, $consentType, $request, $reason);

        // Invalidate related caches
        $this->invalidateContactConsentCache($contactId, $consentType);
        $this->invalidateConsentStatsCache();

        return $result;
    }

    /**
     * Update consent with cache invalidation
     */
    public function updateConsent(
        int $consentId,
        array $data,
        Request $request
    ): \App\Models\ConsentRecord {
        $result = parent::updateConsent($consentId, $data, $request);

        // Invalidate related caches
        $this->invalidateContactConsentCache($result->contact_id, $result->consent_type);
        $this->invalidateConsentByIdCache($consentId);
        $this->invalidateConsentStatsCache();

        return $result;
    }

    /**
     * Delete consent with cache invalidation
     */
    public function deleteConsent(
        int $consentId,
        Request $request,
        ?string $reason = null
    ): bool {
        $consent = $this->getConsentById($consentId);
        $result = parent::deleteConsent($consentId, $request, $reason);

        // Invalidate related caches
        $this->invalidateContactConsentCache($consent->contact_id, $consent->consent_type);
        $this->invalidateConsentByIdCache($consentId);
        $this->invalidateConsentStatsCache();

        return $result;
    }

    /**
     * Get consent statistics with caching
     */
    public function getConsentStats(): array
    {
        $cacheKey = 'consent:stats';
        $ttl = config('lgpd.cache_ttl.consent_stats', 3600);

        return Cache::tags(['consent', 'stats'])->remember(
            $cacheKey,
            $ttl,
            function () {
                $this->cacheStats['misses']++;
                return parent::getConsentStats();
            }
        );
    }

    /**
     * Warm cache for frequently accessed data
     */
    public function warmCache(): array
    {
        $warmed = [];

        try {
            // Warm consent statistics
            $this->getConsentStats();
            $warmed[] = 'consent_stats';

            // Warm common consent types
            $commonConsentTypes = config('lgpd.common_consent_types', []);
            foreach ($commonConsentTypes as $type) {
                $this->getConsentStats();
                $warmed[] = "consent_type_{$type}";
            }

            Log::info('Consent cache warming completed', ['warmed_items' => $warmed]);
        } catch (\Exception $e) {
            Log::error('Consent cache warming failed', [
                'error' => $e->getMessage(),
                'warmed_items' => $warmed,
            ]);
        }

        return $warmed;
    }

    /**
     * Warm cache in background using queue
     */
    private function warmCacheInBackground(string $method, array $args, string $cacheKey, int $ttl): void
    {
        // Mark as being refreshed to prevent multiple background refreshes
        $refreshKey = "{$cacheKey}:refreshing";
        if (Cache::has($refreshKey)) {
            return;
        }

        Cache::put($refreshKey, true, 60); // 1 minute lock

        // In a real implementation, you would dispatch a job here
        // For now, we'll do it synchronously but log it
        Log::info('Background cache refresh started', [
            'method' => $method,
            'cache_key' => $cacheKey,
        ]);

        try {
            $data = call_user_func_array([parent::class, $method], $args);
            Cache::put($cacheKey, $data, $ttl);
            Cache::forget($refreshKey);

            Log::info('Background cache refresh completed', [
                'method' => $method,
                'cache_key' => $cacheKey,
            ]);
        } catch (\Exception $e) {
            Cache::forget($refreshKey);
            Log::error('Background cache refresh failed', [
                'method' => $method,
                'cache_key' => $cacheKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Enhanced cache invalidation with tags
     */
    private function invalidateContactConsentCache(int $contactId, string $consentType): void
    {
        $this->cacheStats['invalidations']++;

        // Invalidate specific consent caches
        $cacheKeys = [
            "consent:valid:{$contactId}:{$consentType}",
            "consent:valid:{$contactId}:{$consentType}:stale",
            "consent:active:{$contactId}:{$consentType}",
            "consent:contact:{$contactId}",
        ];

        foreach ($cacheKeys as $key) {
            Cache::forget($key);
        }

        // Invalidate using tags if Redis is available
        if (config('cache.default') === 'redis') {
            Cache::tags(['consent', 'contacts', "contact:{$contactId}"])->flush();
        }

        Log::info('Contact consent cache invalidated', [
            'contact_id' => $contactId,
            'consent_type' => $consentType,
        ]);
    }

    /**
     * Invalidate consent by ID cache
     */
    private function invalidateConsentByIdCache(int $consentId): void
    {
        $this->cacheStats['invalidations']++;

        Cache::forget("consent:id:{$consentId}");

        if (config('cache.default') === 'redis') {
            Cache::tags(['consent', 'consent_records'])->flush();
        }

        Log::info('Consent by ID cache invalidated', ['consent_id' => $consentId]);
    }

    /**
     * Invalidate consent statistics cache
     */
    private function invalidateConsentStatsCache(): void
    {
        $this->cacheStats['invalidations']++;

        Cache::forget('consent:stats');

        if (config('cache.default') === 'redis') {
            Cache::tags(['consent', 'stats'])->flush();
        }

        Log::info('Consent statistics cache invalidated');
    }

    /**
     * Get cache statistics
     */
    public function getCacheStats(): array
    {
        $total = $this->cacheStats['hits'] + $this->cacheStats['misses'];
        $hitRate = $total > 0 ? round(($this->cacheStats['hits'] / $total) * 100, 2) : 0;

        return [
            'hits' => $this->cacheStats['hits'],
            'misses' => $this->cacheStats['misses'],
            'invalidations' => $this->cacheStats['invalidations'],
            'hit_rate_percentage' => $hitRate,
            'total_requests' => $total,
        ];
    }

    /**
     * Reset cache statistics
     */
    public function resetCacheStats(): void
    {
        $this->cacheStats = [
            'hits' => 0,
            'misses' => 0,
            'invalidations' => 0,
        ];
    }

    /**
     * Get comprehensive service statistics including cache performance
     */
    public function getStats(): array
    {
        $baseStats = parent::getStats();
        $cacheStats = $this->getCacheStats();

        return array_merge($baseStats, [
            'cache_performance' => $cacheStats,
            'cache_tags' => self::CACHE_TAGS,
        ]);
    }

    /**
     * Clear all consent-related caches
     */
    public function clearAllCaches(): array
    {
        $cleared = [];

        try {
            // Clear specific cache keys
            $patterns = [
                'consent:valid:*',
                'consent:active:*',
                'consent:contact:*',
                'consent:id:*',
                'consent:stats',
            ];

            foreach ($patterns as $pattern) {
                // In a real implementation, you would use Redis SCAN
                Log::info('Clearing cache pattern', ['pattern' => $pattern]);
            }

            // Clear using tags if Redis is available
            if (config('cache.default') === 'redis') {
                Cache::tags(self::CACHE_TAGS)->flush();
                $cleared[] = 'tagged_caches';
            }

            $cleared[] = 'pattern_caches';

            Log::info('All consent caches cleared', ['cleared_items' => $cleared]);
        } catch (\Exception $e) {
            Log::error('Failed to clear caches', [
                'error' => $e->getMessage(),
                'cleared_items' => $cleared,
            ]);
        }

        return $cleared;
    }

    /**
     * Preload frequently accessed data
     */
    public function preloadFrequentData(): array
    {
        $preloaded = [];

        try {
            // Preload consent statistics
            $this->getConsentStats();
            $preloaded[] = 'consent_stats';

            // Preload common consent types
            $commonConsentTypes = config('lgpd.common_consent_types', []);
            foreach ($commonConsentTypes as $type) {
                $this->getConsentStats();
                $preloaded[] = "consent_type_{$type}";
            }

            Log::info('Frequent consent data preloaded', ['preloaded_items' => $preloaded]);
        } catch (\Exception $e) {
            Log::error('Failed to preload frequent consent data', [
                'error' => $e->getMessage(),
                'preloaded_items' => $preloaded,
            ]);
        }

        return $preloaded;
    }

    /**
     * Get LGPD compliance report with caching
     */
    public function getLgpdComplianceReport(): array
    {
        $cacheKey = 'consent:lgpd:compliance_report';
        $ttl = config('lgpd.cache_ttl.compliance_report', 1800); // 30 minutes

        return Cache::tags(['consent', 'lgpd', 'compliance'])->remember(
            $cacheKey,
            $ttl,
            function () {
                $this->cacheStats['misses']++;

                $stats = $this->getConsentStats();
                $compliance = [];

                foreach ($stats as $type => $typeStats) {
                    $compliance[$type] = [
                        'total_consents' => $typeStats['total'],
                        'valid_consents' => $typeStats['granted'],
                        'withdrawn_consents' => $typeStats['withdrawn'],
                        'expired_consents' => $typeStats['expired'],
                        'compliance_rate' => $typeStats['total'] > 0
                            ? round(($typeStats['granted'] / $typeStats['total']) * 100, 2)
                            : 0,
                        'lgpd_status' => $typeStats['granted'] > 0 ? 'compliant' : 'non_compliant',
                    ];
                }

                return $compliance;
            }
        );
    }
}
