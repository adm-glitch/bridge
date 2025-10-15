# ConsentService Usage Guide

## Overview

The enhanced ConsentService provides a robust, production-ready interface for LGPD (Lei Geral de Proteção de Dados) compliance with comprehensive consent management, audit trails, data retention enforcement, and security measures.

## Features

- **LGPD Compliance**: Full compliance with Brazilian data protection law
- **Consent Lifecycle Management**: Grant, withdraw, update, and track consents
- **Audit Trail**: Complete audit logging for all consent operations
- **Data Retention**: Automated enforcement of retention policies
- **Security Measures**: Encryption, validation, and access controls
- **Performance Optimization**: Intelligent caching with TTL strategies
- **Rate Limiting**: Protection against abuse
- **Circuit Breaker**: Prevents cascading failures
- **Comprehensive Error Handling**: Detailed error information with LGPD compliance status

## Basic Usage

### Service Registration

The service is automatically registered in Laravel's service container. You can inject it into your controllers or use it directly:

```php
use App\Services\ConsentService;
use App\Services\CachedConsentService;

// Basic service
$consentService = app(ConsentService::class);

// Cached service with advanced caching
$cachedService = app(CachedConsentService::class);
```

### Granting Consent

```php
use App\Services\ConsentService;
use App\Exceptions\ConsentException;
use Illuminate\Http\Request;

try {
    $consentService = app(ConsentService::class);
    
    $consent = $consentService->grantConsent(
        contactId: 123,
        consentType: 'data_processing',
        request: $request,
        chatwootContactId: 456
    );
    
    echo "Consent granted with ID: " . $consent->id;
    echo "LGPD Status: " . $consent->getLgpdComplianceStatus();
    
} catch (ConsentException $e) {
    // Handle consent-specific errors
    if ($e->isRetryable()) {
        echo "Temporary error, will retry: " . $e->getUserMessage();
    } else {
        echo "Permanent error: " . $e->getUserMessage();
    }
    
    // Log detailed error information
    Log::error('Consent operation error', $e->toArray());
}
```

### Checking Consent Validity

```php
use App\Services\CachedConsentService;

$cachedService = app(CachedConsentService::class);

try {
    // This will use cache if available, otherwise check database
    $hasValidConsent = $cachedService->hasValidConsent(123, 'data_processing');
    
    if ($hasValidConsent) {
        echo "Contact has valid consent for data processing";
    } else {
        echo "Contact does not have valid consent";
    }
    
} catch (ConsentException $e) {
    echo "Error checking consent: " . $e->getUserMessage();
}
```

### Withdrawing Consent

```php
try {
    $consent = $consentService->withdrawConsent(
        contactId: 123,
        consentType: 'marketing',
        request: $request,
        reason: 'User requested withdrawal'
    );
    
    echo "Consent withdrawn successfully";
    echo "Withdrawal reason: " . $consent->withdrawal_reason;
    
} catch (ConsentException $e) {
    echo "Error withdrawing consent: " . $e->getUserMessage();
}
```

### Getting Contact Consents

```php
try {
    $consents = $cachedService->getContactConsents(123);
    
    foreach ($consents as $consent) {
        echo "Consent Type: " . $consent['consent_type'];
        echo "Status: " . $consent['status'];
        echo "Granted At: " . $consent['granted_at'];
        echo "LGPD Compliance: " . $consent['lgpd_compliance_status'];
    }
    
} catch (ConsentException $e) {
    echo "Error fetching consents: " . $e->getUserMessage();
}
```

## Advanced Features

### LGPD Compliance Reporting

```php
// Get comprehensive compliance report
$complianceReport = $cachedService->getLgpdComplianceReport();

foreach ($complianceReport as $type => $stats) {
    echo "Consent Type: {$type}";
    echo "Total Consents: {$stats['total_consents']}";
    echo "Valid Consents: {$stats['valid_consents']}";
    echo "Compliance Rate: {$stats['compliance_rate']}%";
    echo "LGPD Status: {$stats['lgpd_status']}";
}
```

### Data Retention Enforcement

```php
// Enforce data retention policies
$processedConsents = $consentService->enforceDataRetention();

echo "Processed {$processedConsents} expired consents";
```

### Consent Statistics

```php
// Get consent statistics
$stats = $consentService->getConsentStats();

foreach ($stats as $type => $typeStats) {
    echo "Consent Type: {$type}";
    echo "Total: {$typeStats['total']}";
    echo "Granted: {$typeStats['granted']}";
    echo "Withdrawn: {$typeStats['withdrawn']}";
    echo "Expired: {$typeStats['expired']}";
}
```

### Cache Management (CachedConsentService)

```php
$cachedService = app(CachedConsentService::class);

// Warm cache with frequently accessed data
$warmed = $cachedService->warmCache();
echo "Cache warmed for: " . implode(', ', $warmed);

// Get cache performance statistics
$cacheStats = $cachedService->getCacheStats();
echo "Cache hit rate: " . $cacheStats['hit_rate_percentage'] . "%";

// Clear all caches
$cleared = $cachedService->clearAllCaches();
echo "Cleared caches: " . implode(', ', $cleared);
```

## Configuration

### Environment Variables

Add these to your `.env` file:

```env
# LGPD Configuration
LGPD_CONSENT_VERSION=2.1
LGPD_RETENTION_DAYS=1825

# Consent Validity Days
LGPD_CONSENT_VALIDITY_DATA_PROCESSING=365
LGPD_CONSENT_VALIDITY_MARKETING=365
LGPD_CONSENT_VALIDITY_HEALTH_DATA=730
LGPD_CONSENT_VALIDITY_ANALYTICS=365

# Consent Texts (Portuguese)
LGPD_CONSENT_TEXT_DATA_PROCESSING="Autorizo o processamento dos meus dados pessoais conforme a Lei Geral de Proteção de Dados (LGPD)."
LGPD_CONSENT_TEXT_MARKETING="Autorizo o envio de comunicações de marketing."
LGPD_CONSENT_TEXT_HEALTH_DATA="Autorizo o armazenamento e processamento de dados sensíveis de saúde para fins de atendimento médico."
LGPD_CONSENT_TEXT_ANALYTICS="Autorizo o uso de dados para análise e melhoria dos serviços."

# Retention Policies
LGPD_RETENTION_DATA_PROCESSING=1825
LGPD_RETENTION_MARKETING=365
LGPD_RETENTION_HEALTH_DATA=2555
LGPD_RETENTION_ANALYTICS=365

# Cache TTL Settings
LGPD_CACHE_CONSENT_VALIDITY_TTL=300
LGPD_CACHE_CONSENT_ACTIVE_TTL=300
LGPD_CACHE_CONTACT_CONSENTS_TTL=600
LGPD_CACHE_CONSENT_BY_ID_TTL=600
LGPD_CACHE_CONSENT_STATS_TTL=3600
LGPD_CACHE_COMPLIANCE_REPORT_TTL=1800

# Common consent types for cache warming
LGPD_COMMON_CONSENT_TYPES="data_processing,marketing"

# Audit retention
LGPD_AUDIT_RETENTION_DAYS=2555

# Encryption settings
LGPD_ENCRYPTION_ENABLED=true
LGPD_ENCRYPTION_ALGORITHM=AES-256-CBC
LGPD_ENCRYPTION_KEY=your_encryption_key_here
```

### Service Configuration

The service configuration is in `config/services.php`:

```php
'lgpd' => [
    'consent_version' => env('LGPD_CONSENT_VERSION', '2.1'),
    'retention_days' => env('LGPD_RETENTION_DAYS', 1825),
    'consent_types' => [
        'data_processing' => 'Data Processing Consent',
        'marketing' => 'Marketing Communications Consent',
        'health_data' => 'Health Data Consent',
        'analytics' => 'Analytics and Performance Consent',
    ],
    'consent_validity_days' => [
        'data_processing' => env('LGPD_CONSENT_VALIDITY_DATA_PROCESSING', 365),
        'marketing' => env('LGPD_CONSENT_VALIDITY_MARKETING', 365),
        'health_data' => env('LGPD_CONSENT_VALIDITY_HEALTH_DATA', 730),
        'analytics' => env('LGPD_CONSENT_VALIDITY_ANALYTICS', 365),
    ],
    'consent_texts' => [
        'data_processing' => env('LGPD_CONSENT_TEXT_DATA_PROCESSING', '...'),
        'marketing' => env('LGPD_CONSENT_TEXT_MARKETING', '...'),
        'health_data' => env('LGPD_CONSENT_TEXT_HEALTH_DATA', '...'),
        'analytics' => env('LGPD_CONSENT_TEXT_ANALYTICS', '...'),
    ],
    'retention_policies' => [
        'data_processing' => env('LGPD_RETENTION_DATA_PROCESSING', 1825),
        'marketing' => env('LGPD_RETENTION_MARKETING', 365),
        'health_data' => env('LGPD_RETENTION_HEALTH_DATA', 2555),
        'analytics' => env('LGPD_RETENTION_ANALYTICS', 365),
    ],
    'cache_ttl' => [
        'consent_validity' => env('LGPD_CACHE_CONSENT_VALIDITY_TTL', 300),
        'consent_active' => env('LGPD_CACHE_CONSENT_ACTIVE_TTL', 300),
        'contact_consents' => env('LGPD_CACHE_CONTACT_CONSENTS_TTL', 600),
        'consent_by_id' => env('LGPD_CACHE_CONSENT_BY_ID_TTL', 600),
        'consent_stats' => env('LGPD_CACHE_CONSENT_STATS_TTL', 3600),
        'compliance_report' => env('LGPD_CACHE_COMPLIANCE_REPORT_TTL', 1800),
    ],
],
```

## Error Handling

### Exception Types

The service throws `ConsentException` for all consent-related errors:

```php
use App\Exceptions\ConsentException;

try {
    $consent = $consentService->grantConsent(123, 'data_processing', $request);
} catch (ConsentException $e) {
    // Check if the error is retryable
    if ($e->isRetryable()) {
        // Temporary error - could retry later
        Log::warning('Temporary consent error', $e->toArray());
    } else {
        // Permanent error - fix the request
        Log::error('Permanent consent error', $e->toArray());
    }
    
    // Get user-friendly error message
    $userMessage = $e->getUserMessage();
    
    // Get error code for programmatic handling
    $errorCode = $e->getErrorCode();
    
    // Get LGPD compliance status
    $lgpdStatus = $e->getLgpdComplianceStatus();
    
    // Check if requires immediate attention
    if ($e->requiresImmediateAttention()) {
        // Send alert to compliance team
    }
}
```

### Error Codes

| Code | Description | LGPD Status | Action |
|------|-------------|-------------|---------|
| `CONSENT_NOT_FOUND` | Consent record not found | compliant | Create new consent |
| `CONSENT_ALREADY_EXISTS` | Consent already exists | compliant | Use existing consent |
| `CONSENT_INVALID_TYPE` | Invalid consent type | non_compliant | Fix consent type |
| `CONSENT_EXPIRED` | Consent has expired | non_compliant | Request new consent |
| `CONSENT_WITHDRAWN` | Consent has been withdrawn | compliant | Respect withdrawal |
| `CONSENT_SERVICE_UNAVAILABLE` | Service temporarily unavailable | unknown | Retry later |
| `CONSENT_DATABASE_ERROR` | Database error | non_compliant | Fix database issue |
| `CONSENT_CACHE_ERROR` | Cache error | unknown | Retry operation |
| `CONSENT_VALIDATION_ERROR` | Validation failed | non_compliant | Fix validation |
| `CONSENT_AUDIT_ERROR` | Audit log failed | non_compliant | Fix audit system |
| `CONSENT_PERMISSION_DENIED` | Insufficient permissions | non_compliant | Check permissions |
| `CONSENT_RATE_LIMIT_EXCEEDED` | Rate limit exceeded | unknown | Wait and retry |

## Performance Optimization

### Caching Strategy

The service implements multiple caching levels:

1. **Consent Validity**: 5-minute TTL with stale-while-revalidate
2. **Active Consents**: 5-minute TTL
3. **Contact Consents**: 10-minute TTL
4. **Consent by ID**: 10-minute TTL
5. **Statistics**: 1-hour TTL
6. **Compliance Reports**: 30-minute TTL

### Cache Warming

Preload frequently accessed data:

```php
$cachedService = app(CachedConsentService::class);

// Warm cache with common data
$warmed = $cachedService->warmCache();

// Preload specific data
$preloaded = $cachedService->preloadFrequentData();
```

### Monitoring

Monitor service performance:

```php
$stats = $consentService->getStats();
$cacheStats = $cachedService->getCacheStats();

// Log performance metrics
Log::info('Consent Service Performance', [
    'rate_limit_remaining' => $stats['rate_limit_remaining'],
    'circuit_breaker_failures' => $stats['circuit_breaker_failures'],
    'cache_hit_rate' => $cacheStats['hit_rate_percentage'],
    'lgpd_compliance' => $stats['lgpd_compliance_status'],
]);
```

## Best Practices

1. **Always use try-catch blocks** around consent operations
2. **Check LGPD compliance status** for all errors
3. **Use the cached service** for read-heavy operations
4. **Monitor performance** with built-in statistics
5. **Warm caches** during low-traffic periods
6. **Handle rate limits** gracefully with user feedback
7. **Log errors** with full context for debugging
8. **Respect consent withdrawals** immediately
9. **Maintain audit trails** for compliance
10. **Enforce data retention** policies

## Integration Examples

### In Controllers

```php
class ConsentController extends Controller
{
    public function grantConsent(Request $request)
    {
        try {
            $consentService = app(ConsentService::class);
            
            $consent = $consentService->grantConsent(
                contactId: $request->input('contact_id'),
                consentType: $request->input('consent_type'),
                request: $request,
                chatwootContactId: $request->input('chatwoot_contact_id')
            );
            
            return response()->json([
                'success' => true,
                'consent_id' => $consent->id,
                'lgpd_status' => $consent->getLgpdComplianceStatus()
            ]);
            
        } catch (ConsentException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getUserMessage(),
                'error_code' => $e->getErrorCode(),
                'lgpd_status' => $e->getLgpdComplianceStatus()
            ], $e->getCode() ?: 500);
        }
    }
}
```

### In Jobs

```php
class ProcessConsentWithdrawal implements ShouldQueue
{
    public function handle(ConsentService $consentService)
    {
        try {
            $consent = $consentService->withdrawConsent(
                contactId: $this->contactId,
                consentType: $this->consentType,
                request: $this->request,
                reason: $this->reason
            );
            
            // Process successful withdrawal
            Log::info('Consent withdrawn successfully', [
                'consent_id' => $consent->id,
                'lgpd_status' => $consent->getLgpdComplianceStatus()
            ]);
            
        } catch (ConsentException $e) {
            if ($e->isRetryable()) {
                // Retry the job
                throw $e;
            } else {
                // Mark job as failed
                $this->fail($e);
            }
        }
    }
}
```

### In Middleware

```php
class ConsentMiddleware
{
    public function handle(Request $request, Closure $next, string $consentType)
    {
        $consentService = app(CachedConsentService::class);
        
        $contactId = $request->input('contact_id');
        
        if (!$consentService->hasValidConsent($contactId, $consentType)) {
            return response()->json([
                'error' => 'Consent required',
                'consent_type' => $consentType,
                'lgpd_compliance' => 'non_compliant'
            ], 403);
        }
        
        return $next($request);
    }
}
```

This enhanced ConsentService provides a robust, production-ready foundation for LGPD compliance while maintaining high performance, security, and reliability with comprehensive audit trails and data retention enforcement.
