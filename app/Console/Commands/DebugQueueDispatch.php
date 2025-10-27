<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Queue;
use Illuminate\Contracts\Bus\Dispatcher;
use App\Jobs\ProcessConversationCreated;

class DebugQueueDispatch extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'debug:queue-dispatch {--test-job : Test with actual ProcessConversationCreated job}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Debug Laravel queue dispatch issues - jobs return ID but never appear in Redis';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('=== Laravel Queue Dispatch Debugging ===');
        $this->newLine();

        // 1. Configuration Check
        $this->info('1. CONFIGURATION CHECK:');
        $this->line('   QUEUE_CONNECTION: ' . config('queue.default'));
        $this->line('   REDIS_CLIENT: ' . config('database.redis.client'));
        $this->line('   REDIS_HOST: ' . config('database.redis.default.host'));
        $this->line('   REDIS_PORT: ' . config('database.redis.default.port'));
        $this->line('   REDIS_DB: ' . config('database.redis.default.database'));
        $this->line('   REDIS_PREFIX: ' . config('database.redis.options.prefix'));
        $this->line('   REDIS_CLUSTER: ' . config('database.redis.options.cluster'));
        $this->line('   REDIS_REPLICATION: ' . config('database.redis.options.replication'));
        $this->newLine();

        // 2. Redis Connection Test
        $this->info('2. REDIS CONNECTION TEST:');
        try {
            $redis = Redis::connection();
            $ping = $redis->ping();
            $this->line('   Redis PING: ' . ($ping ? 'SUCCESS' : 'FAILED'));

            // Test basic Redis operations
            $testKey = 'debug_test_' . time();
            $redis->set($testKey, 'test_value', 60);
            $value = $redis->get($testKey);
            $this->line('   Redis SET/GET: ' . ($value === 'test_value' ? 'SUCCESS' : 'FAILED'));
            $redis->del($testKey);

            // Check Redis info
            $info = $redis->info();
            $this->line('   Redis Version: ' . ($info['redis_version'] ?? 'Unknown'));
            $this->line('   Redis Mode: ' . ($info['redis_mode'] ?? 'Unknown'));
        } catch (\Exception $e) {
            $this->error('   Redis Connection ERROR: ' . $e->getMessage());
        }
        $this->newLine();

        // 3. Queue Manager Check
        $this->info('3. QUEUE MANAGER CHECK:');
        try {
            $queueManager = app('queue');
            $this->line('   Queue Manager Class: ' . get_class($queueManager));

            $queue = $queueManager->connection();
            $this->line('   Queue Connection Class: ' . get_class($queue));
            $this->line('   Queue Connection Name: ' . $queueManager->getDefaultDriver());

            // Check if queue is RedisQueue
            if ($queue instanceof \Illuminate\Queue\RedisQueue) {
                $this->line('   Queue is RedisQueue: YES');

                // Get Redis connection from queue
                $queueRedis = $queue->getRedis();
                $this->line('   Queue Redis Class: ' . get_class($queueRedis));

                // Queue Redis connection verified
                $this->line('   Queue Redis connection: VERIFIED');
            } else {
                $this->line('   Queue is RedisQueue: NO');
            }
        } catch (\Exception $e) {
            $this->error('   Queue Manager ERROR: ' . $e->getMessage());
        }
        $this->newLine();

        // 4. Test Job Dispatch
        $this->info('4. JOB DISPATCH TEST:');

        if ($this->option('test-job')) {
            $this->testProcessConversationCreatedJob();
        } else {
            $this->testSimpleJob();
        }

        $this->newLine();

        // 5. Redis Queue Inspection
        $this->info('5. REDIS QUEUE INSPECTION:');
        $this->inspectRedisQueues();
        $this->newLine();

        // 6. Check for Redis Sentinel Issues
        $this->info('6. REDIS SENTINEL CONFIGURATION CHECK:');
        $this->checkRedisSentinel();
        $this->newLine();

        // 7. Check for Transaction Issues
        $this->info('7. TRANSACTION CHECK:');
        $this->checkTransactions();
        $this->newLine();

        $this->info('=== DEBUGGING COMPLETE ===');
        $this->line('Check the output above for any errors or warnings.');
        $this->line('Common issues to look for:');
        $this->line('1. Redis connection problems');
        $this->line('2. Sentinel configuration issues');
        $this->line('3. Queue configuration mismatches');
        $this->line('4. Active database transactions');
        $this->line('5. Redis prefix/namespace issues');
        $this->line('6. Queue driver not set to \'redis\'');
    }

    private function testSimpleJob()
    {
        try {
            // Test 1: Direct dispatch with ProcessConversationCreated
            $this->line('   Testing direct dispatch...');
            $testPayload = [
                'id' => 'test-conversation-' . time(),
                'contact' => [
                    'id' => 'test-contact-' . time(),
                    'name' => 'Test Contact',
                    'email' => 'test@example.com',
                    'phone_number' => '+1234567890'
                ],
                'status' => 'open',
                'created_at' => now()->toISOString()
            ];

            $job = new ProcessConversationCreated(
                'test-webhook-' . time(),
                $testPayload,
                '127.0.0.1',
                'DebugCommand/1.0'
            );

            $pendingDispatch = $job->dispatch();
            $this->line('   Direct dispatch: ' . get_class($pendingDispatch));

            // Test 2: Dispatcher dispatch
            $this->line('   Testing dispatcher dispatch...');
            $dispatcher = app('Illuminate\Contracts\Bus\Dispatcher');
            $job2 = new ProcessConversationCreated(
                'test-webhook-2-' . time(),
                $testPayload,
                '127.0.0.1',
                'DebugCommand/1.0'
            );
            $jobId2 = $dispatcher->dispatch($job2);
            $this->line('   Dispatcher dispatch job ID: ' . $jobId2);

            // Test 3: Queue push
            $this->line('   Testing queue push...');
            $queue = app('queue')->connection();
            $job3 = new ProcessConversationCreated(
                'test-webhook-3-' . time(),
                $testPayload,
                '127.0.0.1',
                'DebugCommand/1.0'
            );
            $jobId3 = $queue->push($job3);
            $this->line('   Queue push job ID: ' . $jobId3);

            // Wait a moment for jobs to be processed
            sleep(2);
        } catch (\Exception $e) {
            $this->error('   Job Dispatch ERROR: ' . $e->getMessage());
            $this->line('   Stack trace: ' . $e->getTraceAsString());
        }
    }

    private function testProcessConversationCreatedJob()
    {
        try {
            $this->line('   Testing ProcessConversationCreated job...');

            $testPayload = [
                'id' => 'test-conversation-' . time(),
                'contact' => [
                    'id' => 'test-contact-' . time(),
                    'name' => 'Test Contact',
                    'email' => 'test@example.com',
                    'phone_number' => '+1234567890'
                ],
                'status' => 'open',
                'created_at' => now()->toISOString()
            ];

            $job = new ProcessConversationCreated(
                'test-webhook-' . time(),
                $testPayload,
                '127.0.0.1',
                'DebugCommand/1.0'
            );

            $jobId = $job->dispatch()->onQueue('webhooks-high');
            $this->line('   ProcessConversationCreated job ID: ' . $jobId);

            // Wait a moment
            sleep(2);
        } catch (\Exception $e) {
            $this->error('   ProcessConversationCreated Job ERROR: ' . $e->getMessage());
            $this->line('   Stack trace: ' . $e->getTraceAsString());
        }
    }

    private function inspectRedisQueues()
    {
        try {
            $redis = Redis::connection();
            $prefix = config('database.redis.options.prefix');

            // List all keys with prefix
            $keys = $redis->keys($prefix . '*');
            $this->line('   Total keys with prefix: ' . count($keys));

            // Look for queue-specific keys
            $queueKeys = array_filter($keys, function ($key) use ($prefix) {
                return strpos($key, $prefix . 'queues:') !== false;
            });

            $this->line('   Queue keys found: ' . count($queueKeys));
            foreach ($queueKeys as $key) {
                $queueName = str_replace($prefix, '', $key);
                $length = $redis->llen($key);
                $this->line("     - $queueName: $length jobs");

                if ($length > 0) {
                    // Show first job in queue
                    $firstJob = $redis->lindex($key, 0);
                    $this->line("       First job: " . substr($firstJob, 0, 100) . "...");
                }
            }

            // Check for delayed jobs
            $delayedKeys = array_filter($keys, function ($key) use ($prefix) {
                return strpos($key, $prefix . 'queues:delayed') !== false;
            });

            $this->line('   Delayed queue keys: ' . count($delayedKeys));
            foreach ($delayedKeys as $key) {
                $queueName = str_replace($prefix, '', $key);
                $length = $redis->zcard($key);
                $this->line("     - $queueName: $length delayed jobs");
            }
        } catch (\Exception $e) {
            $this->error('   Redis Queue Inspection ERROR: ' . $e->getMessage());
        }
    }

    private function checkRedisSentinel()
    {
        try {
            $redis = Redis::connection();

            // Check if we're connected to Sentinel
            $info = $redis->info();
            if (isset($info['redis_mode']) && $info['redis_mode'] === 'sentinel') {
                $this->line('   Redis Mode: Sentinel');

                // Check Sentinel masters
                $masters = $redis->sentinel('masters');
                $this->line('   Sentinel Masters: ' . count($masters));

                foreach ($masters as $master) {
                    $this->line("     - Master: " . $master['name'] . " (" . $master['ip'] . ":" . $master['port'] . ")");
                }
            } else {
                $this->line('   Redis Mode: Standalone');
            }

            // Check if we're connected to master or replica
            $role = $redis->role();
            $this->line('   Redis Role: ' . $role[0]);

            if ($role[0] === 'slave') {
                $this->warn('   WARNING: Connected to Redis replica, not master!');
                $this->warn('   This could cause queue dispatch issues.');
            }
        } catch (\Exception $e) {
            $this->error('   Sentinel Check ERROR: ' . $e->getMessage());
        }
    }

    private function checkTransactions()
    {
        try {
            $db = app('db');
            $connection = $db->connection();

            if ($connection->transactionLevel() > 0) {
                $this->warn('   WARNING: Database transaction is active (level: ' . $connection->transactionLevel() . ')');
                $this->warn('   This could prevent queue jobs from being dispatched.');
            } else {
                $this->line('   No active database transactions');
            }
        } catch (\Exception $e) {
            $this->error('   Transaction Check ERROR: ' . $e->getMessage());
        }
    }
}
