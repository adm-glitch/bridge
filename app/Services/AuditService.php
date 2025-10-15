<?php

namespace App\Services;

use App\Models\User;
use App\Models\AuditLog;
use App\Jobs\ProcessAuditLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class AuditService
{
    /**
     * Log authentication events
     */
    public function logAuthentication(User $user, string $action, string $ip, string $userAgent): void
    {
        $auditData = [
            'user_id' => $user->id,
            'action' => $action,
            'model' => 'User',
            'model_id' => $user->id,
            'changes' => [
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'action' => $action,
                'timestamp' => now()->toIso8601String()
            ],
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'created_at' => now()
        ];

        // Queue the audit log processing for better performance
        ProcessAuditLog::dispatch($auditData)
            ->onQueue('audit-logs')
            ->delay(now()->addSeconds(1));
    }

    /**
     * Log data access events
     */
    public function logDataAccess(User $user, string $model, int $modelId, string $action, array $changes = []): void
    {
        $auditData = [
            'user_id' => $user->id,
            'action' => $action,
            'model' => $model,
            'model_id' => $modelId,
            'changes' => $changes,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now()
        ];

        // Queue the audit log processing
        ProcessAuditLog::dispatch($auditData)
            ->onQueue('audit-logs')
            ->delay(now()->addSeconds(1));
    }

    /**
     * Log data modification events
     */
    public function logDataModification(User $user, string $model, int $modelId, string $action, array $oldData = [], array $newData = []): void
    {
        $changes = [
            'old_data' => $oldData,
            'new_data' => $newData,
            'modified_fields' => array_keys(array_diff_assoc($newData, $oldData))
        ];

        $this->logDataAccess($user, $model, $modelId, $action, $changes);
    }

    /**
     * Log data deletion events
     */
    public function logDataDeletion(User $user, string $model, int $modelId, array $deletedData = []): void
    {
        $this->logDataAccess($user, $model, $modelId, 'delete', [
            'deleted_data' => $deletedData,
            'deletion_reason' => 'User requested deletion'
        ]);
    }

    /**
     * Log LGPD compliance events
     */
    public function logLgpdEvent(User $user, string $event, int $contactId, array $details = []): void
    {
        $auditData = [
            'user_id' => $user->id,
            'action' => $event,
            'model' => 'Contact',
            'model_id' => $contactId,
            'changes' => array_merge($details, [
                'lgpd_event' => true,
                'timestamp' => now()->toIso8601String()
            ]),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now()
        ];

        ProcessAuditLog::dispatch($auditData)
            ->onQueue('audit-logs')
            ->delay(now()->addSeconds(1));
    }

    /**
     * Log API access events
     */
    public function logApiAccess(User $user, string $endpoint, string $method, int $statusCode, float $responseTime): void
    {
        $auditData = [
            'user_id' => $user->id,
            'action' => 'api_access',
            'model' => 'API',
            'model_id' => null,
            'changes' => [
                'endpoint' => $endpoint,
                'method' => $method,
                'status_code' => $statusCode,
                'response_time_ms' => $responseTime,
                'timestamp' => now()->toIso8601String()
            ],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now()
        ];

        // Only log API access for sensitive endpoints or errors
        if ($this->shouldLogApiAccess($endpoint, $statusCode)) {
            ProcessAuditLog::dispatch($auditData)
                ->onQueue('audit-logs')
                ->delay(now()->addSeconds(1));
        }
    }

    /**
     * Log security events
     */
    public function logSecurityEvent(string $event, array $details = []): void
    {
        $auditData = [
            'user_id' => null,
            'action' => $event,
            'model' => 'Security',
            'model_id' => null,
            'changes' => array_merge($details, [
                'security_event' => true,
                'timestamp' => now()->toIso8601String()
            ]),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now()
        ];

        ProcessAuditLog::dispatch($auditData)
            ->onQueue('audit-logs-high') // High priority for security events
            ->delay(now()->addSeconds(1));
    }

    /**
     * Get audit logs for a user
     */
    public function getUserAuditLogs(int $userId, int $limit = 100): array
    {
        return AuditLog::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Get audit logs for a specific model
     */
    public function getModelAuditLogs(string $model, int $modelId, int $limit = 100): array
    {
        return AuditLog::where('model', $model)
            ->where('model_id', $modelId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Check if API access should be logged
     */
    private function shouldLogApiAccess(string $endpoint, int $statusCode): bool
    {
        // Log all 4xx and 5xx responses
        if ($statusCode >= 400) {
            return true;
        }

        // Log access to sensitive endpoints
        $sensitiveEndpoints = [
            '/api/v1/lgpd/',
            '/api/v1/ai/insights/',
            '/api/v1/auth/',
            '/api/v1/webhooks/'
        ];

        foreach ($sensitiveEndpoints as $sensitiveEndpoint) {
            if (str_contains($endpoint, $sensitiveEndpoint)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clean up old audit logs (for data retention)
     */
    public function cleanupOldLogs(int $retentionDays = 365): int
    {
        $cutoffDate = now()->subDays($retentionDays);

        $deletedCount = AuditLog::where('created_at', '<', $cutoffDate)
            ->delete();

        Log::info('Audit logs cleanup completed', [
            'deleted_count' => $deletedCount,
            'retention_days' => $retentionDays,
            'cutoff_date' => $cutoffDate->toIso8601String()
        ]);

        return $deletedCount;
    }
}
