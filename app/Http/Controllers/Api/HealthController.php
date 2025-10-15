<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Health\HealthCheckRequest;
use App\Services\HealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class HealthController extends Controller
{
    private HealthService $healthService;

    public function __construct(HealthService $healthService)
    {
        $this->healthService = $healthService;
    }

    /**
     * GET /api/health
     * Basic health check endpoint (public)
     */
    public function index(): JsonResponse
    {
        try {
            $health = $this->healthService->getBasicHealth();

            $statusCode = $health['status'] === 'healthy' ? 200 : 503;

            return response()->json([
                'status' => $health['status'],
                'timestamp' => now()->toIso8601String(),
                'service' => 'Healthcare CRM Bridge',
                'version' => '2.1',
                'uptime' => $health['uptime'],
                'response_time_ms' => $health['response_time_ms']
            ], $statusCode);
        } catch (\Exception $e) {
            Log::error('Health check failed', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'unhealthy',
                'timestamp' => now()->toIso8601String(),
                'service' => 'Healthcare CRM Bridge',
                'version' => '2.1',
                'error' => 'Health check failed'
            ], 503);
        }
    }

    /**
     * GET /api/v1/health/detailed
     * Detailed health check with all system components
     */
    public function detailed(HealthCheckRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $includeMetrics = $validated['include_metrics'] ?? false;
            $includeQueues = $validated['include_queues'] ?? false;

            $health = $this->healthService->getDetailedHealth($includeMetrics, $includeQueues);

            $overallHealthy = collect($health['checks'])->every(fn($check) => $check['status'] === 'ok');
            $statusCode = $overallHealthy ? 200 : 503;

            return response()->json([
                'status' => $overallHealthy ? 'healthy' : 'degraded',
                'timestamp' => now()->toIso8601String(),
                'service' => 'Healthcare CRM Bridge',
                'version' => '2.1',
                'checks' => $health['checks'],
                'metrics' => $includeMetrics ? $health['metrics'] : null,
                'queues' => $includeQueues ? $health['queues'] : null,
                'response_time_ms' => $health['response_time_ms']
            ], $statusCode);
        } catch (\Exception $e) {
            Log::error('Detailed health check failed', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'unhealthy',
                'timestamp' => now()->toIso8601String(),
                'service' => 'Healthcare CRM Bridge',
                'version' => '2.1',
                'error' => 'Detailed health check failed'
            ], 503);
        }
    }

    /**
     * GET /api/v1/health/readiness
     * Kubernetes readiness probe
     */
    public function readiness(): JsonResponse
    {
        try {
            $readiness = $this->healthService->getReadinessStatus();

            $statusCode = $readiness['ready'] ? 200 : 503;

            return response()->json([
                'ready' => $readiness['ready'],
                'timestamp' => now()->toIso8601String(),
                'checks' => $readiness['checks'],
                'message' => $readiness['message']
            ], $statusCode);
        } catch (\Exception $e) {
            Log::error('Readiness check failed', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'ready' => false,
                'timestamp' => now()->toIso8601String(),
                'error' => 'Readiness check failed'
            ], 503);
        }
    }

    /**
     * GET /api/v1/health/liveness
     * Kubernetes liveness probe
     */
    public function liveness(): JsonResponse
    {
        try {
            $liveness = $this->healthService->getLivenessStatus();

            return response()->json([
                'alive' => $liveness['alive'],
                'timestamp' => now()->toIso8601String(),
                'uptime' => $liveness['uptime'],
                'memory_usage' => $liveness['memory_usage']
            ], 200);
        } catch (\Exception $e) {
            Log::error('Liveness check failed', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'alive' => false,
                'timestamp' => now()->toIso8601String(),
                'error' => 'Liveness check failed'
            ], 503);
        }
    }

    /**
     * GET /api/v1/health/metrics
     * System metrics and performance data
     */
    public function metrics(): JsonResponse
    {
        try {
            $metrics = $this->healthService->getSystemMetrics();

            return response()->json([
                'success' => true,
                'timestamp' => now()->toIso8601String(),
                'metrics' => $metrics
            ], 200);
        } catch (\Exception $e) {
            Log::error('Metrics retrieval failed', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Metrics retrieval failed',
                'error_code' => 'METRICS_RETRIEVAL_FAILED',
                'timestamp' => now()->toIso8601String()
            ], 500);
        }
    }

    /**
     * GET /api/v1/health/queues
     * Queue status and monitoring
     */
    public function queues(): JsonResponse
    {
        try {
            $queues = $this->healthService->getQueueStatus();

            return response()->json([
                'success' => true,
                'timestamp' => now()->toIso8601String(),
                'queues' => $queues
            ], 200);
        } catch (\Exception $e) {
            Log::error('Queue status retrieval failed', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Queue status retrieval failed',
                'error_code' => 'QUEUE_STATUS_FAILED',
                'timestamp' => now()->toIso8601String()
            ], 500);
        }
    }
}
