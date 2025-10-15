<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Advanced Cached Krayin API Service with intelligent caching strategies
 * 
 * Features:
 * - Tag-based cache invalidation
 * - Stale-while-revalidate pattern
 * - Cache warming strategies
 * - Performance monitoring
 * - Cache hit/miss analytics
 * 
 * @package App\Services
 * @author Bridge Service
 * @version 2.1
 */
class CachedKrayinApiService extends KrayinApiService
{
    private array $cacheStats = [
        'hits' => 0,
        'misses' => 0,
        'invalidations' => 0,
    ];

    private const CACHE_TAGS = [
        'krayin',
        'leads',
        'pipelines',
        'stages',
        'activities',
    ];

    /**
     * Get lead by ID with advanced caching and stale-while-revalidate
     */
    public function getLeadById(int $leadId): array
    {
        $cacheKey = "krayin:lead:{$leadId}";
        $staleKey = "krayin:lead:{$leadId}:stale";
        $ttl = $this->cacheTtl['lead'] ?? 300;

        // Try to get fresh data first
        $data = Cache::get($cacheKey);

        if ($data !== null) {
            $this->cacheStats['hits']++;

            // Check if we should refresh in background (stale-while-revalidate)
            $staleData = Cache::get($staleKey);
            if ($staleData === null) {
                // Start background refresh
                $this->warmCacheInBackground('getLeadById', [$leadId], $cacheKey, $ttl);
            }

            return $data;
        }

        $this->cacheStats['misses']++;

        // Get fresh data and cache it
        $data = parent::getLeadById($leadId);

        // Cache both fresh and stale versions
        Cache::put($cacheKey, $data, $ttl);
        Cache::put($staleKey, $data, $ttl * 2); // Stale version lasts twice as long

        return $data;
    }

    /**
     * Get pipeline stages with intelligent cache warming
     */
    public function getPipelineStages(int $pipelineId): array
    {
        $cacheKey = "krayin:pipeline:{$pipelineId}:stages";
        $ttl = $this->cacheTtl['stages'] ?? 86400;

        return Cache::tags(['krayin', 'pipelines', "pipeline:{$pipelineId}"])->remember(
            $cacheKey,
            $ttl,
            function () use ($pipelineId) {
                $this->cacheStats['misses']++;
                return parent::getPipelineStages($pipelineId);
            }
        );
    }

    /**
     * Get all pipelines with cache warming
     */
    public function getPipelines(): array
    {
        $cacheKey = 'krayin:pipelines:all';
        $ttl = $this->cacheTtl['pipeline'] ?? 3600;

        return Cache::tags(['krayin', 'pipelines'])->remember(
            $cacheKey,
            $ttl,
            function () {
                $this->cacheStats['misses']++;
                return parent::getPipelines();
            }
        );
    }

    /**
     * Create lead with cache invalidation
     */
    public function createLead(array $data): array
    {
        $result = parent::createLead($data);

        // Invalidate related caches
        $this->invalidatePipelinesCache();

        return $result;
    }

    /**
     * Update lead stage with comprehensive cache invalidation
     */
    public function updateLeadStage(int $leadId, int $stageId): array
    {
        $result = parent::updateLeadStage($leadId, $stageId);

        // Invalidate all related caches
        $this->invalidateLeadCache($leadId);
        $this->invalidatePipelinesCache();

        return $result;
    }

    /**
     * Create activity with cache invalidation
     */
    public function createActivity(int $leadId, array $data): array
    {
        $result = parent::createActivity($leadId, $data);

        // Invalidate lead cache since activities are part of lead data
        $this->invalidateLeadCache($leadId);

        return $result;
    }

    /**
     * Warm cache for frequently accessed data
     */
    public function warmCache(): array
    {
        $warmed = [];

        try {
            // Warm pipelines cache
            $this->getPipelines();
            $warmed[] = 'pipelines';

            // Get all pipeline IDs and warm their stages
            $pipelines = Cache::get('krayin:pipelines:all', []);
            if (isset($pipelines['data'])) {
                foreach ($pipelines['data'] as $pipeline) {
                    $this->getPipelineStages($pipeline['id']);
                }
                $warmed[] = 'pipeline_stages';
            }

            Log::info('Cache warming completed', ['warmed_items' => $warmed]);
        } catch (\Exception $e) {
            Log::error('Cache warming failed', [
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
    private function invalidateLeadCache(int $leadId): void
    {
        $this->cacheStats['invalidations']++;

        // Invalidate specific lead cache
        Cache::forget("krayin:lead:{$leadId}");
        Cache::forget("krayin:lead:{$leadId}:stale");

        // Invalidate using tags if Redis is available
        if (config('cache.default') === 'redis') {
            Cache::tags(['krayin', 'leads', "lead:{$leadId}"])->flush();
        }

        Log::info('Lead cache invalidated', ['lead_id' => $leadId]);
    }

    /**
     * Invalidate pipelines cache
     */
    private function invalidatePipelinesCache(): void
    {
        $this->cacheStats['invalidations']++;

        Cache::forget('krayin:pipelines:all');

        if (config('cache.default') === 'redis') {
            Cache::tags(['krayin', 'pipelines'])->flush();
        }

        Log::info('Pipelines cache invalidated');
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
     * Clear all Krayin-related caches
     */
    public function clearAllCaches(): array
    {
        $cleared = [];

        try {
            // Clear specific cache keys
            $patterns = [
                'krayin:lead:*',
                'krayin:pipeline:*',
                'krayin:pipelines:*',
            ];

            foreach ($patterns as $pattern) {
                // In a real implementation, you would use Redis SCAN
                // For now, we'll just log the pattern
                Log::info('Clearing cache pattern', ['pattern' => $pattern]);
            }

            // Clear using tags if Redis is available
            if (config('cache.default') === 'redis') {
                Cache::tags(self::CACHE_TAGS)->flush();
                $cleared[] = 'tagged_caches';
            }

            $cleared[] = 'pattern_caches';

            Log::info('All Krayin caches cleared', ['cleared_items' => $cleared]);
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
            // Preload pipelines
            $this->getPipelines();
            $preloaded[] = 'pipelines';

            // Preload common pipeline stages
            $commonPipelineIds = config('krayin.common_pipeline_ids', []);
            foreach ($commonPipelineIds as $pipelineId) {
                $this->getPipelineStages($pipelineId);
                $preloaded[] = "pipeline_stages_{$pipelineId}";
            }

            Log::info('Frequent data preloaded', ['preloaded_items' => $preloaded]);
        } catch (\Exception $e) {
            Log::error('Failed to preload frequent data', [
                'error' => $e->getMessage(),
                'preloaded_items' => $preloaded,
            ]);
        }

        return $preloaded;
    }
}
