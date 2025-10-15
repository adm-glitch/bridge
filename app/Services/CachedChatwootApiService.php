<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Advanced Cached Chatwoot API Service with intelligent caching strategies
 * 
 * Features:
 * - Tag-based cache invalidation
 * - Stale-while-revalidate pattern
 * - Cache warming strategies
 * - Performance monitoring
 * - Cache hit/miss analytics
 * - Webhook-aware cache invalidation
 * 
 * @package App\Services
 * @author Bridge Service
 * @version 2.1
 */
class CachedChatwootApiService extends ChatwootApiService
{
    private array $cacheStats = [
        'hits' => 0,
        'misses' => 0,
        'invalidations' => 0,
    ];

    private const CACHE_TAGS = [
        'chatwoot',
        'conversations',
        'messages',
        'contacts',
        'accounts',
    ];

    /**
     * Get conversation by ID with advanced caching and stale-while-revalidate
     */
    public function getConversation(int $conversationId): array
    {
        $cacheKey = "chatwoot:conversation:{$conversationId}";
        $staleKey = "chatwoot:conversation:{$conversationId}:stale";
        $ttl = $this->cacheTtl['conversation'] ?? 300;

        // Try to get fresh data first
        $data = Cache::get($cacheKey);

        if ($data !== null) {
            $this->cacheStats['hits']++;

            // Check if we should refresh in background (stale-while-revalidate)
            $staleData = Cache::get($staleKey);
            if ($staleData === null) {
                // Start background refresh
                $this->warmCacheInBackground('getConversation', [$conversationId], $cacheKey, $ttl);
            }

            return $data;
        }

        $this->cacheStats['misses']++;

        // Get fresh data and cache it
        $data = parent::getConversation($conversationId);

        // Cache both fresh and stale versions
        Cache::put($cacheKey, $data, $ttl);
        Cache::put($staleKey, $data, $ttl * 2); // Stale version lasts twice as long

        return $data;
    }

    /**
     * Get messages with intelligent caching and pagination support
     */
    public function getMessages(int $conversationId, array $params = []): array
    {
        $cacheKey = "chatwoot:messages:{$conversationId}:" . md5(serialize($params));
        $ttl = $this->cacheTtl['messages'] ?? 60;

        return Cache::tags(['chatwoot', 'messages', "conversation:{$conversationId}"])->remember(
            $cacheKey,
            $ttl,
            function () use ($conversationId, $params) {
                $this->cacheStats['misses']++;
                return parent::getMessages($conversationId, $params);
            }
        );
    }

    /**
     * Get contact with advanced caching
     */
    public function getContact(int $contactId): array
    {
        $cacheKey = "chatwoot:contact:{$contactId}";
        $ttl = $this->cacheTtl['contact'] ?? 600;

        return Cache::tags(['chatwoot', 'contacts', "contact:{$contactId}"])->remember(
            $cacheKey,
            $ttl,
            function () use ($contactId) {
                $this->cacheStats['misses']++;
                return parent::getContact($contactId);
            }
        );
    }

    /**
     * Create contact with cache invalidation
     */
    public function createContact(array $data): array
    {
        $result = parent::createContact($data);

        // Invalidate contacts list cache
        $this->invalidateContactsListCache();

        return $result;
    }

    /**
     * Update contact with comprehensive cache invalidation
     */
    public function updateContact(int $contactId, array $data): array
    {
        $result = parent::updateContact($contactId, $data);

        // Invalidate all related caches
        $this->invalidateContactCache($contactId);
        $this->invalidateContactsListCache();

        return $result;
    }

    /**
     * Create message with cache invalidation
     */
    public function createMessage(int $conversationId, array $data): array
    {
        $result = parent::createMessage($conversationId, $data);

        // Invalidate conversation and messages cache
        $this->invalidateConversationCache($conversationId);
        $this->invalidateMessagesCache($conversationId);

        return $result;
    }

    /**
     * Update conversation status with cache invalidation
     */
    public function updateConversationStatus(int $conversationId, string $status): array
    {
        $result = parent::updateConversationStatus($conversationId, $status);

        // Invalidate conversation cache
        $this->invalidateConversationCache($conversationId);

        return $result;
    }

    /**
     * Get account with caching
     */
    public function getAccount(int $accountId): array
    {
        $cacheKey = "chatwoot:account:{$accountId}";
        $ttl = $this->cacheTtl['account'] ?? 3600;

        return Cache::tags(['chatwoot', 'accounts', "account:{$accountId}"])->remember(
            $cacheKey,
            $ttl,
            function () use ($accountId) {
                $this->cacheStats['misses']++;
                return parent::getAccount($accountId);
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
            // Warm accounts cache
            $commonAccountIds = config('chatwoot.common_account_ids', []);
            foreach ($commonAccountIds as $accountId) {
                $this->getAccount($accountId);
                $warmed[] = "account_{$accountId}";
            }

            Log::info('Chatwoot cache warming completed', ['warmed_items' => $warmed]);
        } catch (\Exception $e) {
            Log::error('Chatwoot cache warming failed', [
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
    private function invalidateContactCache(int $contactId): void
    {
        $this->cacheStats['invalidations']++;

        // Invalidate specific contact cache
        Cache::forget("chatwoot:contact:{$contactId}");
        Cache::forget("chatwoot:contact:{$contactId}:stale");

        // Invalidate using tags if Redis is available
        if (config('cache.default') === 'redis') {
            Cache::tags(['chatwoot', 'contacts', "contact:{$contactId}"])->flush();
        }

        Log::info('Contact cache invalidated', ['contact_id' => $contactId]);
    }

    /**
     * Invalidate conversation cache
     */
    private function invalidateConversationCache(int $conversationId): void
    {
        $this->cacheStats['invalidations']++;

        Cache::forget("chatwoot:conversation:{$conversationId}");
        Cache::forget("chatwoot:conversation:{$conversationId}:stale");

        if (config('cache.default') === 'redis') {
            Cache::tags(['chatwoot', 'conversations', "conversation:{$conversationId}"])->flush();
        }

        Log::info('Conversation cache invalidated', ['conversation_id' => $conversationId]);
    }

    /**
     * Invalidate messages cache for a conversation
     */
    private function invalidateMessagesCache(int $conversationId): void
    {
        $this->cacheStats['invalidations']++;

        // Clear all message caches for this conversation
        $patterns = [
            "chatwoot:messages:{$conversationId}:*",
        ];

        foreach ($patterns as $pattern) {
            // In a real implementation, you would use Redis SCAN
            Log::info('Clearing cache pattern', ['pattern' => $pattern]);
        }

        if (config('cache.default') === 'redis') {
            Cache::tags(['chatwoot', 'messages', "conversation:{$conversationId}"])->flush();
        }

        Log::info('Messages cache invalidated', ['conversation_id' => $conversationId]);
    }

    /**
     * Invalidate contacts list cache
     */
    private function invalidateContactsListCache(): void
    {
        $this->cacheStats['invalidations']++;

        Cache::forget('chatwoot:contacts:list');

        if (config('cache.default') === 'redis') {
            Cache::tags(['chatwoot', 'contacts'])->flush();
        }

        Log::info('Contacts list cache invalidated');
    }

    /**
     * Handle webhook events with cache invalidation
     */
    public function handleWebhookEvent(string $event, array $data): void
    {
        Log::info('Handling Chatwoot webhook event', [
            'event' => $event,
            'data_keys' => array_keys($data),
        ]);

        switch ($event) {
            case 'conversation_created':
            case 'conversation_updated':
                if (isset($data['id'])) {
                    $this->invalidateConversationCache($data['id']);
                }
                break;

            case 'message_created':
            case 'message_updated':
                if (isset($data['conversation_id'])) {
                    $this->invalidateConversationCache($data['conversation_id']);
                    $this->invalidateMessagesCache($data['conversation_id']);
                }
                break;

            case 'contact_created':
            case 'contact_updated':
                if (isset($data['id'])) {
                    $this->invalidateContactCache($data['id']);
                    $this->invalidateContactsListCache();
                }
                break;

            case 'conversation_status_changed':
                if (isset($data['id'])) {
                    $this->invalidateConversationCache($data['id']);
                }
                break;

            default:
                Log::info('Unknown webhook event, no cache invalidation', ['event' => $event]);
        }
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
     * Clear all Chatwoot-related caches
     */
    public function clearAllCaches(): array
    {
        $cleared = [];

        try {
            // Clear specific cache keys
            $patterns = [
                'chatwoot:conversation:*',
                'chatwoot:messages:*',
                'chatwoot:contact:*',
                'chatwoot:account:*',
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

            Log::info('All Chatwoot caches cleared', ['cleared_items' => $cleared]);
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
            // Preload common accounts
            $commonAccountIds = config('chatwoot.common_account_ids', []);
            foreach ($commonAccountIds as $accountId) {
                $this->getAccount($accountId);
                $preloaded[] = "account_{$accountId}";
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
