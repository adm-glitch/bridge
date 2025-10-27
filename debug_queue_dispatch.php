<?php

/**
 * Laravel Queue Dispatch Debugging Script
 * 
 * This script helps debug why Laravel jobs return job IDs but never appear in Redis.
 * Run this script to trace the entire queue dispatch flow.
 */

require_once 'vendor/autoload.php';

use Illuminate\Foundation\Application;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

// Bootstrap Laravel application
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== Laravel Queue Dispatch Debugging ===\n\n";

// 1. Check current configuration
echo "1. CONFIGURATION CHECK:\n";
echo "   QUEUE_CONNECTION: " . config('queue.default') . "\n";
echo "   REDIS_CLIENT: " . config('database.redis.client') . "\n";
echo "   REDIS_HOST: " . config('database.redis.default.host') . "\n";
echo "   REDIS_PORT: " . config('database.redis.default.port') . "\n";
echo "   REDIS_DB: " . config('database.redis.default.database') . "\n";
echo "   REDIS_PREFIX: " . config('database.redis.options.prefix') . "\n";
echo "   REDIS_CLUSTER: " . config('database.redis.options.cluster') . "\n";
echo "   REDIS_REPLICATION: " . config('database.redis.options.replication') . "\n\n";

// 2. Test Redis connection
echo "2. REDIS CONNECTION TEST:\n";
try {
    $redis = Redis::connection();
    $ping = $redis->ping();
    echo "   Redis PING: " . ($ping ? 'SUCCESS' : 'FAILED') . "\n";
    
    // Test basic Redis operations
    $testKey = 'debug_test_' . time();
    $redis->set($testKey, 'test_value', 60);
    $value = $redis->get($testKey);
    echo "   Redis SET/GET: " . ($value === 'test_value' ? 'SUCCESS' : 'FAILED') . "\n";
    $redis->del($testKey);
    
    // Check Redis info
    $info = $redis->info();
    echo "   Redis Version: " . ($info['redis_version'] ?? 'Unknown') . "\n";
    echo "   Redis Mode: " . ($info['redis_mode'] ?? 'Unknown') . "\n";
    
} catch (Exception $e) {
    echo "   Redis Connection ERROR: " . $e->getMessage() . "\n";
}
echo "\n";

// 3. Check queue manager
echo "3. QUEUE MANAGER CHECK:\n";
try {
    $queueManager = app('queue');
    echo "   Queue Manager Class: " . get_class($queueManager) . "\n";
    
    $queue = $queueManager->connection();
    echo "   Queue Connection Class: " . get_class($queue) . "\n";
    echo "   Queue Connection Name: " . $queueManager->getDefaultDriver() . "\n";
    
    // Check if queue is RedisQueue
    if ($queue instanceof \Illuminate\Queue\RedisQueue) {
        echo "   Queue is RedisQueue: YES\n";
        
        // Get Redis connection from queue
        $queueRedis = $queue->getRedis();
        echo "   Queue Redis Class: " . get_class($queueRedis) . "\n";
        
        // Test queue Redis connection
        $queuePing = $queueRedis->ping();
        echo "   Queue Redis PING: " . ($queuePing ? 'SUCCESS' : 'FAILED') . "\n";
        
    } else {
        echo "   Queue is RedisQueue: NO\n";
    }
    
} catch (Exception $e) {
    echo "   Queue Manager ERROR: " . $e->getMessage() . "\n";
}
echo "\n";

// 4. Check bus dispatcher
echo "4. BUS DISPATCHER CHECK:\n";
try {
    $dispatcher = app('Illuminate\Contracts\Bus\Dispatcher');
    echo "   Dispatcher Class: " . get_class($dispatcher) . "\n";
    
    // Check if dispatcher has queue manager
    if (method_exists($dispatcher, 'getQueueManager')) {
        $queueManager = $dispatcher->getQueueManager();
        echo "   Dispatcher Queue Manager: " . get_class($queueManager) . "\n";
    }
    
} catch (Exception $e) {
    echo "   Bus Dispatcher ERROR: " . $e->getMessage() . "\n";
}
echo "\n";

// 5. Test job dispatch with detailed logging
echo "5. JOB DISPATCH TEST:\n";

// Create a simple test job
class TestDebugJob implements \Illuminate\Contracts\Queue\ShouldQueue
{
    use \Illuminate\Foundation\Bus\Dispatchable, 
        \Illuminate\Queue\InteractsWithQueue, 
        \Illuminate\Queue\Queueable, 
        \Illuminate\Queue\SerializesModels;
    
    public $tries = 3;
    public $timeout = 60;
    
    public function __construct(public string $testData)
    {
        //
    }
    
    public function handle()
    {
        echo "   Test job executed successfully!\n";
        Log::info('TestDebugJob executed', ['test_data' => $this->testData]);
    }
}

try {
    // Test 1: Direct dispatch
    echo "   Testing direct dispatch...\n";
    $job = new TestDebugJob('direct_test');
    $jobId = $job->dispatch();
    echo "   Direct dispatch job ID: " . $jobId . "\n";
    
    // Test 2: Dispatcher dispatch
    echo "   Testing dispatcher dispatch...\n";
    $dispatcher = app('Illuminate\Contracts\Bus\Dispatcher');
    $job2 = new TestDebugJob('dispatcher_test');
    $jobId2 = $dispatcher->dispatch($job2);
    echo "   Dispatcher dispatch job ID: " . $jobId2 . "\n";
    
    // Test 3: Queue push
    echo "   Testing queue push...\n";
    $queue = app('queue')->connection();
    $job3 = new TestDebugJob('queue_push_test');
    $jobId3 = $queue->push($job3);
    echo "   Queue push job ID: " . $jobId3 . "\n";
    
    // Wait a moment for jobs to be processed
    sleep(2);
    
} catch (Exception $e) {
    echo "   Job Dispatch ERROR: " . $e->getMessage() . "\n";
    echo "   Stack trace: " . $e->getTraceAsString() . "\n";
}
echo "\n";

// 6. Check Redis queues
echo "6. REDIS QUEUE INSPECTION:\n";
try {
    $redis = Redis::connection();
    $prefix = config('database.redis.options.prefix');
    
    // List all keys with prefix
    $keys = $redis->keys($prefix . '*');
    echo "   Total keys with prefix: " . count($keys) . "\n";
    
    // Look for queue-specific keys
    $queueKeys = array_filter($keys, function($key) use ($prefix) {
        return strpos($key, $prefix . 'queues:') !== false;
    });
    
    echo "   Queue keys found: " . count($queueKeys) . "\n";
    foreach ($queueKeys as $key) {
        $queueName = str_replace($prefix, '', $key);
        $length = $redis->llen($key);
        echo "     - $queueName: $length jobs\n";
        
        if ($length > 0) {
            // Show first job in queue
            $firstJob = $redis->lindex($key, 0);
            echo "       First job: " . substr($firstJob, 0, 100) . "...\n";
        }
    }
    
    // Check for delayed jobs
    $delayedKeys = array_filter($keys, function($key) use ($prefix) {
        return strpos($key, $prefix . 'queues:delayed') !== false;
    });
    
    echo "   Delayed queue keys: " . count($delayedKeys) . "\n";
    foreach ($delayedKeys as $key) {
        $queueName = str_replace($prefix, '', $key);
        $length = $redis->zcard($key);
        echo "     - $queueName: $length delayed jobs\n";
    }
    
} catch (Exception $e) {
    echo "   Redis Queue Inspection ERROR: " . $e->getMessage() . "\n";
}
echo "\n";

// 7. Check for Redis Sentinel configuration issues
echo "7. REDIS SENTINEL CONFIGURATION CHECK:\n";
try {
    $redis = Redis::connection();
    
    // Check if we're connected to Sentinel
    $info = $redis->info();
    if (isset($info['redis_mode']) && $info['redis_mode'] === 'sentinel') {
        echo "   Redis Mode: Sentinel\n";
        
        // Check Sentinel masters
        $masters = $redis->sentinel('masters');
        echo "   Sentinel Masters: " . count($masters) . "\n";
        
        foreach ($masters as $master) {
            echo "     - Master: " . $master['name'] . " (" . $master['ip'] . ":" . $master['port'] . ")\n";
        }
        
    } else {
        echo "   Redis Mode: Standalone\n";
    }
    
    // Check if we're connected to master or replica
    $role = $redis->role();
    echo "   Redis Role: " . $role[0] . "\n";
    
    if ($role[0] === 'slave') {
        echo "   WARNING: Connected to Redis replica, not master!\n";
        echo "   This could cause queue dispatch issues.\n";
    }
    
} catch (Exception $e) {
    echo "   Sentinel Check ERROR: " . $e->getMessage() . "\n";
}
echo "\n";

// 8. Test manual Redis queue operations
echo "8. MANUAL REDIS QUEUE TEST:\n";
try {
    $redis = Redis::connection();
    $prefix = config('database.redis.options.prefix');
    $queueName = $prefix . 'queues:test_debug';
    
    // Manually push a job to Redis
    $testJob = [
        'uuid' => 'test-' . uniqid(),
        'displayName' => 'TestDebugJob',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'maxTries' => 3,
        'maxExceptions' => null,
        'failOnTimeout' => false,
        'backoff' => null,
        'timeout' => 60,
        'retryUntil' => null,
        'data' => [
            'commandName' => 'TestDebugJob',
            'command' => serialize(new TestDebugJob('manual_test'))
        ]
    ];
    
    $redis->lpush($queueName, json_encode($testJob));
    echo "   Manually pushed job to Redis queue\n";
    
    // Check if job is in queue
    $length = $redis->llen($queueName);
    echo "   Queue length after manual push: $length\n";
    
    // Clean up
    $redis->del($queueName);
    echo "   Cleaned up test queue\n";
    
} catch (Exception $e) {
    echo "   Manual Redis Queue Test ERROR: " . $e->getMessage() . "\n";
}
echo "\n";

// 9. Check Laravel queue configuration
echo "9. LARAVEL QUEUE CONFIGURATION:\n";
$queueConfig = config('queue');
echo "   Default connection: " . $queueConfig['default'] . "\n";
echo "   Redis connection: " . $queueConfig['connections']['redis']['connection'] . "\n";
echo "   Redis queue: " . $queueConfig['connections']['redis']['queue'] . "\n";
echo "   Redis retry_after: " . $queueConfig['connections']['redis']['retry_after'] . "\n";
echo "   Redis block_for: " . ($queueConfig['connections']['redis']['block_for'] ?? 'null') . "\n";
echo "   Redis after_commit: " . ($queueConfig['connections']['redis']['after_commit'] ? 'true' : 'false') . "\n";
echo "\n";

// 10. Check for transaction issues
echo "10. TRANSACTION CHECK:\n";
try {
    $db = app('db');
    $connection = $db->connection();
    
    if ($connection->transactionLevel() > 0) {
        echo "   WARNING: Database transaction is active (level: " . $connection->transactionLevel() . ")\n";
        echo "   This could prevent queue jobs from being dispatched.\n";
    } else {
        echo "   No active database transactions\n";
    }
    
} catch (Exception $e) {
    echo "   Transaction Check ERROR: " . $e->getMessage() . "\n";
}
echo "\n";

echo "=== DEBUGGING COMPLETE ===\n";
echo "Check the output above for any errors or warnings.\n";
echo "Common issues to look for:\n";
echo "1. Redis connection problems\n";
echo "2. Sentinel configuration issues\n";
echo "3. Queue configuration mismatches\n";
echo "4. Active database transactions\n";
echo "5. Redis prefix/namespace issues\n";
echo "6. Queue driver not set to 'redis'\n";
