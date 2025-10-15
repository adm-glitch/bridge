<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class HealthService
{
    private const CACHE_TTL = 60; // 1 minute
    private const TIMEOUT = 5; // 5 seconds

    /**
     * Get basic health status
     */
    public function getBasicHealth(): array
    {
        $startTime = microtime(true);

        try {
            // Check database connection
            DB::connection()->getPdo();
            $dbTime = DB::selectOne('SELECT NOW() as time')->time;

            // Check Redis connection
            Redis::ping();

            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'status' => 'healthy',
                'uptime' => $this->getUptime(),
                'response_time_ms' => $responseTime,
                'database_time' => $dbTime
            ];
        } catch (\Exception $e) {
            Log::error('Basic health check failed', [
                'error' => $e->getMessage()
            ]);

            return [
                'status' => 'unhealthy',
                'uptime' => $this->getUptime(),
                'response_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get detailed health status
     */
    public function getDetailedHealth(bool $includeMetrics = false, bool $includeQueues = false): array
    {
        $startTime = microtime(true);

        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'krayin_api' => $this->checkKrayinApi(),
            'chatwoot_api' => $this->checkChatwootApi(),
            'queue' => $this->checkQueue(),
        ];

        $result = [
            'checks' => $checks,
            'response_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
        ];

        if ($includeMetrics) {
            $result['metrics'] = $this->getSystemMetrics();
        }

        if ($includeQueues) {
            $result['queues'] = $this->getQueueStatus();
        }

        return $result;
    }

    /**
     * Get readiness status for Kubernetes
     */
    public function getReadinessStatus(): array
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'queue' => $this->checkQueue()
        ];

        $ready = collect($checks)->every(fn($check) => $check['status'] === 'ok');

        return [
            'ready' => $ready,
            'checks' => $checks,
            'message' => $ready ? 'Service is ready to accept traffic' : 'Service is not ready'
        ];
    }

    /**
     * Get liveness status for Kubernetes
     */
    public function getLivenessStatus(): array
    {
        try {
            // Basic liveness check
            $memoryUsage = memory_get_usage(true);
            $memoryLimit = ini_get('memory_limit');
            $memoryPercent = $this->parseMemoryLimit($memoryLimit) > 0
                ? ($memoryUsage / $this->parseMemoryLimit($memoryLimit)) * 100
                : 0;

            return [
                'alive' => $memoryPercent < 90, // Not using more than 90% of memory
                'uptime' => $this->getUptime(),
                'memory_usage' => [
                    'bytes' => $memoryUsage,
                    'percent' => round($memoryPercent, 2),
                    'limit' => $memoryLimit
                ]
            ];
        } catch (\Exception $e) {
            Log::error('Liveness check failed', [
                'error' => $e->getMessage()
            ]);

            return [
                'alive' => false,
                'uptime' => $this->getUptime(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get system metrics
     */
    public function getSystemMetrics(): array
    {
        return Cache::remember('system_metrics', self::CACHE_TTL, function () {
            return [
                'memory' => [
                    'usage_bytes' => memory_get_usage(true),
                    'peak_bytes' => memory_get_peak_usage(true),
                    'limit' => ini_get('memory_limit')
                ],
                'database' => $this->getDatabaseMetrics(),
                'redis' => $this->getRedisMetrics(),
                'cache' => $this->getCacheMetrics(),
                'queue' => $this->getQueueMetrics()
            ];
        });
    }

    /**
     * Get queue status
     */
    public function getQueueStatus(): array
    {
        try {
            $queues = [
                'webhooks-high' => $this->getQueueInfo('webhooks-high'),
                'webhooks-normal' => $this->getQueueInfo('webhooks-normal'),
                'lgpd-normal' => $this->getQueueInfo('lgpd-normal'),
                'exports-bulk' => $this->getQueueInfo('exports-bulk'),
                'default' => $this->getQueueInfo('default')
            ];

            return [
                'queues' => $queues,
                'total_jobs' => collect($queues)->sum('size'),
                'failed_jobs' => $this->getFailedJobsCount(),
                'processing_jobs' => $this->getProcessingJobsCount()
            ];
        } catch (\Exception $e) {
            Log::error('Queue status check failed', [
                'error' => $e->getMessage()
            ]);

            return [
                'error' => 'Failed to retrieve queue status',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Check database connection
     */
    private function checkDatabase(): array
    {
        try {
            $start = microtime(true);
            DB::connection()->getPdo();
            $time = DB::selectOne('SELECT NOW() as time')->time;
            $duration = round((microtime(true) - $start) * 1000, 2);

            return [
                'status' => 'ok',
                'message' => 'Database connected',
                'response_time_ms' => $duration,
                'timestamp' => $time
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'response_time_ms' => null
            ];
        }
    }

    /**
     * Check Redis connection
     */
    private function checkRedis(): array
    {
        try {
            $start = microtime(true);
            Redis::ping();
            $duration = round((microtime(true) - $start) * 1000, 2);

            return [
                'status' => 'ok',
                'message' => 'Redis connected',
                'response_time_ms' => $duration
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'response_time_ms' => null
            ];
        }
    }

    /**
     * Check Krayin API
     */
    private function checkKrayinApi(): array
    {
        try {
            $start = microtime(true);
            $response = Http::timeout(self::TIMEOUT)
                ->get(config('services.krayin.base_url') . '/health');
            $duration = round((microtime(true) - $start) * 1000, 2);

            return [
                'status' => $response->successful() ? 'ok' : 'error',
                'message' => $response->successful() ? 'Krayin API accessible' : 'Krayin API error',
                'response_time_ms' => $duration,
                'status_code' => $response->status()
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'response_time_ms' => null
            ];
        }
    }

    /**
     * Check Chatwoot API
     */
    private function checkChatwootApi(): array
    {
        try {
            $start = microtime(true);
            $response = Http::timeout(self::TIMEOUT)
                ->get(config('services.chatwoot.base_url') . '/health');
            $duration = round((microtime(true) - $start) * 1000, 2);

            return [
                'status' => $response->successful() ? 'ok' : 'error',
                'message' => $response->successful() ? 'Chatwoot API accessible' : 'Chatwoot API error',
                'response_time_ms' => $duration,
                'status_code' => $response->status()
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'response_time_ms' => null
            ];
        }
    }

    /**
     * Check queue system
     */
    private function checkQueue(): array
    {
        try {
            $queueSize = Redis::llen('queues:webhooks-high');
            $status = $queueSize > 1000 ? 'warning' : 'ok';

            return [
                'status' => $status,
                'message' => $status === 'warning' ? 'Queue backlog detected' : 'Queue healthy',
                'queue_size' => $queueSize,
                'max_size' => 1000
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Get database metrics
     */
    private function getDatabaseMetrics(): array
    {
        try {
            $connectionCount = DB::selectOne("SELECT count(*) as count FROM pg_stat_activity WHERE state = 'active'")->count;
            $dbSize = DB::selectOne("SELECT pg_size_pretty(pg_database_size(current_database())) as size")->size;

            return [
                'active_connections' => $connectionCount,
                'database_size' => $dbSize,
                'status' => 'ok'
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Get Redis metrics
     */
    private function getRedisMetrics(): array
    {
        try {
            $info = Redis::info();

            return [
                'used_memory' => $info['used_memory_human'] ?? 'unknown',
                'connected_clients' => $info['connected_clients'] ?? 0,
                'total_commands_processed' => $info['total_commands_processed'] ?? 0,
                'keyspace_hits' => $info['keyspace_hits'] ?? 0,
                'keyspace_misses' => $info['keyspace_misses'] ?? 0,
                'status' => 'ok'
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Get cache metrics
     */
    private function getCacheMetrics(): array
    {
        try {
            $cacheStats = Redis::info();

            return [
                'hits' => $cacheStats['keyspace_hits'] ?? 0,
                'misses' => $cacheStats['keyspace_misses'] ?? 0,
                'hit_rate' => $this->calculateHitRate($cacheStats),
                'status' => 'ok'
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Get queue metrics
     */
    private function getQueueMetrics(): array
    {
        try {
            $queues = ['webhooks-high', 'webhooks-normal', 'lgpd-normal', 'exports-bulk', 'default'];
            $totalJobs = 0;
            $queueDetails = [];

            foreach ($queues as $queue) {
                $size = Redis::llen("queues:{$queue}");
                $totalJobs += $size;
                $queueDetails[$queue] = $size;
            }

            return [
                'total_jobs' => $totalJobs,
                'queues' => $queueDetails,
                'failed_jobs' => $this->getFailedJobsCount(),
                'status' => 'ok'
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Get queue information
     */
    private function getQueueInfo(string $queueName): array
    {
        try {
            $size = Redis::llen("queues:{$queueName}");
            $processing = Redis::llen("queues:{$queueName}:reserved");

            return [
                'name' => $queueName,
                'size' => $size,
                'processing' => $processing,
                'status' => $size > 100 ? 'backlog' : 'healthy'
            ];
        } catch (\Exception $e) {
            return [
                'name' => $queueName,
                'size' => 0,
                'processing' => 0,
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get failed jobs count
     */
    private function getFailedJobsCount(): int
    {
        try {
            return Redis::llen('failed');
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get processing jobs count
     */
    private function getProcessingJobsCount(): int
    {
        try {
            $queues = ['webhooks-high', 'webhooks-normal', 'lgpd-normal', 'exports-bulk', 'default'];
            $total = 0;

            foreach ($queues as $queue) {
                $total += Redis::llen("queues:{$queue}:reserved");
            }

            return $total;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get system uptime
     */
    private function getUptime(): string
    {
        try {
            $uptime = shell_exec('uptime -p 2>/dev/null') ?: 'unknown';
            return trim($uptime);
        } catch (\Exception $e) {
            return 'unknown';
        }
    }

    /**
     * Parse memory limit
     */
    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $value = (int) $limit;

        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    /**
     * Calculate cache hit rate
     */
    private function calculateHitRate(array $stats): float
    {
        $hits = $stats['keyspace_hits'] ?? 0;
        $misses = $stats['keyspace_misses'] ?? 0;
        $total = $hits + $misses;

        return $total > 0 ? round(($hits / $total) * 100, 2) : 0;
    }
}
