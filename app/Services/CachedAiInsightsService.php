<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Cached AI Insights Service with SWR, tag invalidation, and warming
 */
class CachedAiInsightsService extends AiInsightsService
{
    private array $cacheStats = [
        'hits' => 0,
        'misses' => 0,
        'invalidations' => 0,
    ];

    private const CACHE_TAGS = [
        'ai_insights',
        'leads',
    ];

    public function getCurrentInsights(int $leadId, array $params = []): array
    {
        $cacheKey = "ai:insights:current:{$leadId}:" . md5(serialize($params));
        $staleKey = $cacheKey . ':stale';
        $ttl = config('services.ai_insights.cache_ttl.current', 3600);

        $data = Cache::get($cacheKey);
        if ($data !== null) {
            $this->cacheStats['hits']++;
            if (!Cache::has($staleKey)) {
                $this->warmCacheInBackground('getCurrentInsights', [$leadId, $params], $cacheKey, $ttl);
            }
            return $data;
        }

        $this->cacheStats['misses']++;
        $data = parent::getCurrentInsights($leadId, $params);
        Cache::put($cacheKey, $data, $ttl);
        Cache::put($staleKey, true, $ttl * 2);
        return $data;
    }

    public function getHistoricalInsights(int $leadId, array $params = []): array
    {
        $cacheKey = "ai:insights:historical:{$leadId}:" . md5(serialize($params));
        $ttl = config('services.ai_insights.cache_ttl.historical', 7200);

        return Cache::tags(['ai_insights', 'leads', "lead:{$leadId}"])->remember(
            $cacheKey,
            $ttl,
            function () use ($leadId, $params) {
                $this->cacheStats['misses']++;
                return parent::getHistoricalInsights($leadId, $params);
            }
        );
    }

    public function clearLeadCaches(int $leadId): void
    {
        $this->cacheStats['invalidations']++;
        Cache::forget("ai:insights:current:{$leadId}");
        Log::info('Cleared AI insights caches for lead', ['lead_id' => $leadId]);
        if (config('cache.default') === 'redis') {
            Cache::tags(['ai_insights', 'leads', "lead:{$leadId}"])->flush();
        }
    }

    private function warmCacheInBackground(string $method, array $args, string $cacheKey, int $ttl): void
    {
        $refreshKey = $cacheKey . ':refreshing';
        if (Cache::has($refreshKey)) { return; }
        Cache::put($refreshKey, true, 60);
        Log::info('AI insights background cache refresh started', ['method' => $method, 'cache_key' => $cacheKey]);
        try {
            $data = call_user_func_array([parent::class, $method], $args);
            Cache::put($cacheKey, $data, $ttl);
        } catch (\Exception $e) {
            Log::error('AI insights background refresh failed', ['error' => $e->getMessage()]);
        } finally {
            Cache::forget($refreshKey);
        }
    }

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

    public function getStats(): array
    {
        return array_merge(parent::getStats(), [
            'cache_performance' => $this->getCacheStats(),
            'cache_tags' => self::CACHE_TAGS,
        ]);
    }
}
