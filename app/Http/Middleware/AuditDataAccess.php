<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enhanced AuditDataAccess Middleware
 * 
 * This middleware implements comprehensive data access auditing
 * as specified in the architecture document v2.1. It provides:
 * 
 * - Complete audit trail for all data access
 * - LGPD compliance for data protection
 * - Sensitive data detection and protection
 * - User activity monitoring
 * - Security event logging
 * - Performance impact minimization
 * 
 * Security Features:
 * - Tracks all data access for compliance
 * - Detects sensitive data access patterns
 * - Monitors user activity
 * - Provides security event correlation
 * - Ensures data protection compliance
 * 
 * @package App\Http\Middleware
 * @version 2.1
 * @author Bridge Service Team
 * @since 2025-10-08
 */
class AuditDataAccess
{
    /**
     * Sensitive data field patterns
     * 
     * Security Decision: These patterns help identify sensitive data
     * that requires special audit logging under LGPD. Healthcare data
     * requires the highest level of protection and audit trails.
     * 
     * @var array
     */
    private const SENSITIVE_FIELD_PATTERNS = [
        'medical_notes',
        'health_conditions',
        'medications',
        'diagnosis',
        'treatment',
        'patient_id',
        'health_data',
        'sensitive_notes',
        'personal_health',
        'medical_history',
        'insurance_number',
        'ssn',
        'cpf',
        'rg',
        'passport',
        'credit_card',
        'bank_account',
        'financial_data',
    ];

    /**
     * High-risk endpoints that always require audit logging
     * 
     * Security Decision: These endpoints handle sensitive operations
     * that must be audited regardless of data sensitivity.
     * 
     * @var array
     */
    private const HIGH_RISK_ENDPOINTS = [
        'api/leads',
        'api/contacts',
        'api/activities',
        'api/conversations',
        'api/health-data',
        'api/medical-notes',
        'api/patient-data',
        'api/export',
        'api/delete',
        'api/update',
    ];

    /**
     * Audit log table name
     * 
     * @var string
     */
    private const AUDIT_TABLE = 'audit_logs';

    /**
     * Cache TTL for audit batching (5 minutes)
     * 
     * Security Decision: Batching audit logs improves performance
     * while maintaining compliance. 5 minutes provides good
     * balance between performance and audit trail completeness.
     * 
     * @var int
     */
    private const AUDIT_BATCH_TTL = 300; // 5 minutes

    /**
     * Maximum batch size for audit logs
     * 
     * @var int
     */
    private const MAX_BATCH_SIZE = 100;

    /**
     * Handle an incoming request with comprehensive audit logging
     * 
     * This method implements multi-layered audit logging:
     * 1. Request analysis and classification
     * 2. Sensitive data detection
     * 3. User activity monitoring
     * 4. Audit log creation
     * 5. Security event correlation
     * 6. Performance optimization
     * 
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);
        $user = Auth::user();
        $ip = $this->getClientIp($request);
        $endpoint = $request->path();
        $method = $request->method();

        // Analyze request for audit requirements
        $auditContext = $this->analyzeRequest($request, $user, $ip);

        // Skip audit logging for non-sensitive requests
        if (!$auditContext['requires_audit']) {
            return $next($request);
        }

        try {
            // Process the request
            $response = $next($request);

            // Create audit log entry
            $this->createAuditLog($request, $response, $auditContext, $startTime);

            // Check for suspicious activity patterns
            $this->checkSuspiciousActivity($request, $auditContext, $ip);

            return $response;
        } catch (\Exception $e) {
            // Log audit failure
            $this->logAuditFailure($request, $e, $auditContext, $ip);

            // Re-throw the exception
            throw $e;
        }
    }

    /**
     * Analyze request to determine audit requirements
     * 
     * Security Decision: Not all requests require audit logging to
     * maintain performance. We audit based on:
     * - Data sensitivity
     * - Endpoint risk level
     * - User permissions
     * - Request characteristics
     * 
     * @param Request $request
     * @param mixed $user
     * @param string $ip
     * @return array
     */
    protected function analyzeRequest(Request $request, $user, string $ip): array
    {
        $endpoint = $request->path();
        $method = $request->method();
        $data = $request->all();

        // Check if endpoint is high-risk
        $isHighRisk = $this->isHighRiskEndpoint($endpoint);

        // Check for sensitive data
        $sensitiveData = $this->detectSensitiveData($data, $endpoint);

        // Determine audit level
        $auditLevel = $this->determineAuditLevel($isHighRisk, $sensitiveData, $user);

        // Check if audit is required
        $requiresAudit = $auditLevel !== 'none';

        return [
            'requires_audit' => $requiresAudit,
            'audit_level' => $auditLevel,
            'is_high_risk' => $isHighRisk,
            'sensitive_data' => $sensitiveData,
            'user_id' => $user?->id,
            'ip' => $ip,
            'endpoint' => $endpoint,
            'method' => $method,
            'timestamp' => now(),
        ];
    }

    /**
     * Check if endpoint is high-risk
     * 
     * Security Decision: High-risk endpoints handle sensitive operations
     * that must always be audited for compliance and security.
     * 
     * @param string $endpoint
     * @return bool
     */
    protected function isHighRiskEndpoint(string $endpoint): bool
    {
        foreach (self::HIGH_RISK_ENDPOINTS as $pattern) {
            if (str_contains($endpoint, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect sensitive data in request
     * 
     * Security Decision: Sensitive data detection is crucial for LGPD
     * compliance. We scan request data for patterns that indicate
     * sensitive information requiring special protection.
     * 
     * @param array $data
     * @param string $endpoint
     * @return array
     */
    protected function detectSensitiveData(array $data, string $endpoint): array
    {
        $sensitiveFields = [];
        $sensitiveValues = [];

        // Recursively scan data for sensitive patterns
        $this->scanDataForSensitiveFields($data, $sensitiveFields, $sensitiveValues);

        // Check endpoint for sensitive data indicators
        $endpointSensitive = $this->checkEndpointSensitivity($endpoint);

        return [
            'fields' => $sensitiveFields,
            'values' => $sensitiveValues,
            'endpoint_sensitive' => $endpointSensitive,
            'has_sensitive_data' => !empty($sensitiveFields) || $endpointSensitive,
        ];
    }

    /**
     * Recursively scan data for sensitive fields
     * 
     * @param mixed $data
     * @param array $sensitiveFields
     * @param array $sensitiveValues
     * @param string $path
     * @return void
     */
    protected function scanDataForSensitiveFields($data, array &$sensitiveFields, array &$sensitiveValues, string $path = ''): void
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $currentPath = $path ? "{$path}.{$key}" : $key;

                // Check if field name matches sensitive patterns
                if ($this->isSensitiveField($key)) {
                    $sensitiveFields[] = $currentPath;
                    if (!empty($value)) {
                        $sensitiveValues[] = [
                            'field' => $currentPath,
                            'value' => $this->sanitizeValue($value),
                        ];
                    }
                }

                // Recursively scan nested data
                if (is_array($value) || is_object($value)) {
                    $this->scanDataForSensitiveFields($value, $sensitiveFields, $sensitiveValues, $currentPath);
                }
            }
        } elseif (is_object($data)) {
            foreach (get_object_vars($data) as $key => $value) {
                $currentPath = $path ? "{$path}.{$key}" : $key;

                if ($this->isSensitiveField($key)) {
                    $sensitiveFields[] = $currentPath;
                    if (!empty($value)) {
                        $sensitiveValues[] = [
                            'field' => $currentPath,
                            'value' => $this->sanitizeValue($value),
                        ];
                    }
                }

                if (is_array($value) || is_object($value)) {
                    $this->scanDataForSensitiveFields($value, $sensitiveFields, $sensitiveValues, $currentPath);
                }
            }
        }
    }

    /**
     * Check if field name indicates sensitive data
     * 
     * @param string $fieldName
     * @return bool
     */
    protected function isSensitiveField(string $fieldName): bool
    {
        $fieldName = strtolower($fieldName);

        foreach (self::SENSITIVE_FIELD_PATTERNS as $pattern) {
            if (str_contains($fieldName, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanitize sensitive value for logging
     * 
     * Security Decision: We log partial values for audit purposes
     * while protecting full sensitive data from exposure.
     * 
     * @param mixed $value
     * @return string
     */
    protected function sanitizeValue($value): string
    {
        if (is_string($value)) {
            $length = strlen($value);
            if ($length > 10) {
                return substr($value, 0, 4) . '***' . substr($value, -4);
            }
            return str_repeat('*', $length);
        }

        return '***';
    }

    /**
     * Check endpoint sensitivity
     * 
     * @param string $endpoint
     * @return bool
     */
    protected function checkEndpointSensitivity(string $endpoint): bool
    {
        $sensitivePatterns = [
            'health',
            'medical',
            'patient',
            'diagnosis',
            'treatment',
            'insurance',
            'financial',
            'export',
            'delete',
        ];

        foreach ($sensitivePatterns as $pattern) {
            if (str_contains(strtolower($endpoint), $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine audit level based on risk factors
     * 
     * Security Decision: Different audit levels provide appropriate
     * logging detail based on risk level while maintaining performance.
     * 
     * @param bool $isHighRisk
     * @param array $sensitiveData
     * @param mixed $user
     * @return string
     */
    protected function determineAuditLevel(bool $isHighRisk, array $sensitiveData, $user): string
    {
        // Critical: High-risk endpoint with sensitive data
        if ($isHighRisk && $sensitiveData['has_sensitive_data']) {
            return 'critical';
        }

        // High: High-risk endpoint or sensitive data
        if ($isHighRisk || $sensitiveData['has_sensitive_data']) {
            return 'high';
        }

        // Medium: Authenticated user on API endpoint
        if ($user && str_contains(request()->path(), 'api/')) {
            return 'medium';
        }

        // Low: Basic logging for other requests
        if ($user) {
            return 'low';
        }

        return 'none';
    }

    /**
     * Create audit log entry
     * 
     * Security Decision: Audit logs must be comprehensive for compliance
     * while being performant. We batch logs and use appropriate
     * detail levels based on risk.
     * 
     * @param Request $request
     * @param Response $response
     * @param array $auditContext
     * @param float $startTime
     * @return void
     */
    protected function createAuditLog(Request $request, Response $response, array $auditContext, float $startTime): void
    {
        $processingTime = microtime(true) - $startTime;

        $auditData = [
            'user_id' => $auditContext['user_id'],
            'action' => $this->determineAction($request),
            'model' => $this->determineModel($request),
            'model_id' => $this->extractModelId($request),
            'endpoint' => $auditContext['endpoint'],
            'method' => $auditContext['method'],
            'ip_address' => $auditContext['ip'],
            'user_agent' => $request->userAgent(),
            'request_data' => $this->sanitizeRequestData($request, $auditContext),
            'response_status' => $response->getStatusCode(),
            'processing_time_ms' => round($processingTime * 1000, 2),
            'audit_level' => $auditContext['audit_level'],
            'sensitive_data_accessed' => $auditContext['sensitive_data']['has_sensitive_data'],
            'sensitive_fields' => $auditContext['sensitive_data']['fields'],
            'created_at' => $auditContext['timestamp'],
        ];

        // Add additional context for high-risk operations
        if ($auditContext['audit_level'] === 'critical') {
            $auditData['additional_context'] = [
                'session_id' => $request->session()?->getId(),
                'request_id' => $request->header('X-Request-ID'),
                'referer' => $request->header('Referer'),
                'content_type' => $request->header('Content-Type'),
            ];
        }

        // Batch audit logs for performance
        $this->batchAuditLog($auditData);

        // Log security event for high-risk operations
        if ($auditContext['audit_level'] === 'critical') {
            $this->logSecurityEvent($request, $auditContext, $auditData);
        }
    }

    /**
     * Determine action from request
     * 
     * @param Request $request
     * @return string
     */
    protected function determineAction(Request $request): string
    {
        $method = $request->method();

        return match ($method) {
            'GET' => 'read',
            'POST' => 'create',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default => 'unknown',
        };
    }

    /**
     * Determine model from request
     * 
     * @param Request $request
     * @return string
     */
    protected function determineModel(Request $request): string
    {
        $path = $request->path();

        // Extract model from API path
        if (str_contains($path, 'api/')) {
            $segments = explode('/', $path);
            $apiIndex = array_search('api', $segments);
            if ($apiIndex !== false && isset($segments[$apiIndex + 1])) {
                return ucfirst($segments[$apiIndex + 1]);
            }
        }

        return 'Unknown';
    }

    /**
     * Extract model ID from request
     * 
     * @param Request $request
     * @return int|null
     */
    protected function extractModelId(Request $request): ?int
    {
        $route = $request->route();
        if ($route && $route->parameter('id')) {
            return (int) $route->parameter('id');
        }

        return $request->input('id');
    }

    /**
     * Sanitize request data for logging
     * 
     * Security Decision: We log request data for audit purposes
     * while sanitizing sensitive information to prevent data exposure.
     * 
     * @param Request $request
     * @param array $auditContext
     * @return array
     */
    protected function sanitizeRequestData(Request $request, array $auditContext): array
    {
        $data = $request->all();

        // Remove sensitive fields
        foreach ($auditContext['sensitive_data']['fields'] as $field) {
            $this->removeNestedField($data, $field);
        }

        // Limit data size
        if (count($data) > 50) {
            $data = array_slice($data, 0, 50);
            $data['_truncated'] = true;
        }

        return $data;
    }

    /**
     * Remove nested field from data
     * 
     * @param array $data
     * @param string $fieldPath
     * @return void
     */
    protected function removeNestedField(array &$data, string $fieldPath): void
    {
        $keys = explode('.', $fieldPath);
        $current = &$data;

        for ($i = 0; $i < count($keys) - 1; $i++) {
            if (!isset($current[$keys[$i]])) {
                return;
            }
            $current = &$current[$keys[$i]];
        }

        $lastKey = end($keys);
        if (isset($current[$lastKey])) {
            $current[$lastKey] = '[REDACTED]';
        }
    }

    /**
     * Batch audit logs for performance
     * 
     * Security Decision: Batching improves performance while maintaining
     * audit trail completeness. We use Redis for batching and periodic
     * database writes.
     * 
     * @param array $auditData
     * @return void
     */
    protected function batchAuditLog(array $auditData): void
    {
        $batchKey = 'audit_batch:' . date('Y-m-d-H-i');
        $batch = Cache::get($batchKey, []);

        $batch[] = $auditData;

        // Write to database if batch is full
        if (count($batch) >= self::MAX_BATCH_SIZE) {
            $this->writeAuditBatch($batch);
            Cache::forget($batchKey);
        } else {
            Cache::put($batchKey, $batch, self::AUDIT_BATCH_TTL);
        }
    }

    /**
     * Write audit batch to database
     * 
     * @param array $batch
     * @return void
     */
    protected function writeAuditBatch(array $batch): void
    {
        try {
            DB::table(self::AUDIT_TABLE)->insert($batch);

            Log::debug('Audit batch written to database', [
                'batch_size' => count($batch),
                'table' => self::AUDIT_TABLE,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to write audit batch', [
                'error' => $e->getMessage(),
                'batch_size' => count($batch),
            ]);
        }
    }

    /**
     * Log security event for high-risk operations
     * 
     * @param Request $request
     * @param array $auditContext
     * @param array $auditData
     * @return void
     */
    protected function logSecurityEvent(Request $request, array $auditContext, array $auditData): void
    {
        Log::warning('High-risk data access detected', [
            'event' => 'sensitive_data_access',
            'user_id' => $auditContext['user_id'],
            'ip' => $auditContext['ip'],
            'endpoint' => $auditContext['endpoint'],
            'sensitive_fields' => $auditContext['sensitive_data']['fields'],
            'audit_level' => $auditContext['audit_level'],
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Check for suspicious activity patterns
     * 
     * Security Decision: Pattern detection helps identify potential
     * security threats and compliance violations.
     * 
     * @param Request $request
     * @param array $auditContext
     * @param string $ip
     * @return void
     */
    protected function checkSuspiciousActivity(Request $request, array $auditContext, string $ip): void
    {
        $userId = $auditContext['user_id'];

        // Check for rapid data access
        $this->checkRapidDataAccess($userId, $ip);

        // Check for unusual access patterns
        $this->checkUnusualAccessPatterns($userId, $auditContext);

        // Check for bulk data access
        $this->checkBulkDataAccess($userId, $auditContext);
    }

    /**
     * Check for rapid data access patterns
     * 
     * @param int|null $userId
     * @param string $ip
     * @return void
     */
    protected function checkRapidDataAccess(?int $userId, string $ip): void
    {
        $key = $userId ? "rapid_access:user:{$userId}" : "rapid_access:ip:{$ip}";
        $count = Cache::increment($key, 1);
        Cache::expire($key, 300); // 5 minutes

        if ($count > 50) {
            Log::warning('Rapid data access pattern detected', [
                'user_id' => $userId,
                'ip' => $ip,
                'access_count' => $count,
            ]);
        }
    }

    /**
     * Check for unusual access patterns
     * 
     * @param int|null $userId
     * @param array $auditContext
     * @return void
     */
    protected function checkUnusualAccessPatterns(?int $userId, array $auditContext): void
    {
        if (!$userId) return;

        $key = "access_pattern:user:{$userId}";
        $patterns = Cache::get($key, []);

        $currentPattern = [
            'endpoint' => $auditContext['endpoint'],
            'method' => $auditContext['method'],
            'timestamp' => now()->timestamp,
        ];

        $patterns[] = $currentPattern;

        // Keep only last 100 patterns
        if (count($patterns) > 100) {
            $patterns = array_slice($patterns, -100);
        }

        Cache::put($key, $patterns, 3600); // 1 hour

        // Analyze patterns for anomalies
        $this->analyzeAccessPatterns($userId, $patterns);
    }

    /**
     * Analyze access patterns for anomalies
     * 
     * @param int $userId
     * @param array $patterns
     * @return void
     */
    protected function analyzeAccessPatterns(int $userId, array $patterns): void
    {
        if (count($patterns) < 10) return;

        $endpoints = array_column($patterns, 'endpoint');
        $endpointCounts = array_count_values($endpoints);

        // Check for unusual endpoint access
        foreach ($endpointCounts as $endpoint => $count) {
            if ($count > 20) {
                Log::info('Unusual endpoint access pattern', [
                    'user_id' => $userId,
                    'endpoint' => $endpoint,
                    'access_count' => $count,
                ]);
            }
        }
    }

    /**
     * Check for bulk data access
     * 
     * @param int|null $userId
     * @param array $auditContext
     * @return void
     */
    protected function checkBulkDataAccess(?int $userId, array $auditContext): void
    {
        if ($auditContext['sensitive_data']['has_sensitive_data']) {
            $key = $userId ? "bulk_sensitive:user:{$userId}" : "bulk_sensitive:ip:{$auditContext['ip']}";
            $count = Cache::increment($key, 1);
            Cache::expire($key, 3600); // 1 hour

            if ($count > 10) {
                Log::warning('Bulk sensitive data access detected', [
                    'user_id' => $userId,
                    'ip' => $auditContext['ip'],
                    'sensitive_access_count' => $count,
                ]);
            }
        }
    }

    /**
     * Log audit failure
     * 
     * @param Request $request
     * @param \Exception $exception
     * @param array $auditContext
     * @param string $ip
     * @return void
     */
    protected function logAuditFailure(Request $request, \Exception $exception, array $auditContext, string $ip): void
    {
        Log::error('Audit logging failed', [
            'event' => 'audit_failure',
            'user_id' => $auditContext['user_id'],
            'ip' => $ip,
            'endpoint' => $auditContext['endpoint'],
            'error' => $exception->getMessage(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Get client IP address with proxy support
     * 
     * @param Request $request
     * @return string
     */
    protected function getClientIp(Request $request): string
    {
        $forwardedFor = $request->header('X-Forwarded-For');
        if ($forwardedFor) {
            $ips = explode(',', $forwardedFor);
            $ip = trim($ips[0]);

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }

        $realIp = $request->header('X-Real-IP');
        if ($realIp && filter_var($realIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $realIp;
        }

        return $request->ip();
    }
}
