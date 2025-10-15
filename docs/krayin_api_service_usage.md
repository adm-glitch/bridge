# KrayinApiService Usage Guide

## Overview

The enhanced KrayinApiService provides a robust, production-ready interface to the Krayin CRM API with comprehensive error handling, retry logic, caching, and security measures.

## Features

- **Exponential Backoff Retry Logic**: Automatically retries failed requests with intelligent backoff
- **Circuit Breaker Pattern**: Prevents cascading failures by temporarily stopping requests when the API is down
- **Rate Limiting**: Built-in protection against API rate limits
- **Intelligent Caching**: Multi-level caching with TTL and invalidation strategies
- **Security Measures**: SSL verification, request sanitization, and security headers
- **Comprehensive Error Handling**: Detailed error information with retry recommendations
- **Performance Monitoring**: Built-in statistics and health checks

## Basic Usage

### Service Registration

The service is automatically registered in Laravel's service container. You can inject it into your controllers or use it directly:

```php
use App\Services\KrayinApiService;
use App\Services\CachedKrayinApiService;

// Basic service
$krayinService = app(KrayinApiService::class);

// Cached service with advanced caching
$cachedService = app(CachedKrayinApiService::class);
```

### Creating a Lead

```php
use App\Services\KrayinApiService;
use App\Exceptions\KrayinApiException;

try {
    $krayinService = app(KrayinApiService::class);
    
    $leadData = [
        'title' => 'João Silva - Consulta',
        'person' => [
            'name' => 'João Silva',
            'emails' => ['joao@example.com'],
            'contact_numbers' => ['+5511999999999']
        ],
        'lead_pipeline_stage_id' => 2
    ];
    
    $result = $krayinService->createLead($leadData);
    
    echo "Lead created with ID: " . $result['data']['id'];
    
} catch (KrayinApiException $e) {
    // Handle API-specific errors
    if ($e->isRetryable()) {
        echo "Temporary error, will retry: " . $e->getUserMessage();
    } else {
        echo "Permanent error: " . $e->getUserMessage();
    }
    
    // Log detailed error information
    Log::error('Krayin API error', $e->toArray());
}
```

### Getting Lead Information with Caching

```php
use App\Services\CachedKrayinApiService;

$cachedService = app(CachedKrayinApiService::class);

try {
    // This will use cache if available, otherwise fetch from API
    $lead = $cachedService->getLeadById(123);
    
    echo "Lead Name: " . $lead['data']['person']['name'];
    
} catch (KrayinApiException $e) {
    echo "Error fetching lead: " . $e->getUserMessage();
}
```

### Updating Lead Stage

```php
try {
    $result = $krayinService->updateLeadStage(123, 3);
    
    // Cache is automatically invalidated
    echo "Lead stage updated successfully";
    
} catch (KrayinApiException $e) {
    echo "Error updating lead: " . $e->getUserMessage();
}
```

## Advanced Features

### Health Monitoring

```php
// Check API health
$health = $krayinService->healthCheck();

if ($health['status'] === 'ok') {
    echo "API is healthy (Response time: {$health['response_time_ms']}ms)";
} else {
    echo "API is unhealthy: " . $health['message'];
}
```

### Service Statistics

```php
// Get service performance statistics
$stats = $krayinService->getStats();

echo "Rate limit remaining: " . $stats['rate_limit_remaining'];
echo "Circuit breaker failures: " . $stats['circuit_breaker_failures'];
```

### Cache Management (CachedKrayinApiService)

```php
$cachedService = app(CachedKrayinApiService::class);

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
# Krayin API Configuration
KRAYIN_URL=https://krayin.yourdomain.com
KRAYIN_API_TOKEN=your_api_token_here
KRAYIN_API_TIMEOUT=10
KRAYIN_API_RETRY_ATTEMPTS=3
KRAYIN_API_RETRY_DELAY=1000

# Cache TTL Settings
KRAYIN_CACHE_LEAD_TTL=300
KRAYIN_CACHE_PIPELINE_TTL=3600
KRAYIN_CACHE_STAGES_TTL=86400

# Security Settings
KRAYIN_VERIFY_SSL=true
KRAYIN_USER_AGENT=Bridge-Service/2.1
KRAYIN_MAX_REDIRECTS=3
```

### Service Configuration

The service configuration is in `config/services.php`:

```php
'krayin' => [
    'base_url' => env('KRAYIN_URL', 'https://krayin.yourdomain.com'),
    'api_token' => env('KRAYIN_API_TOKEN'),
    'timeout' => env('KRAYIN_API_TIMEOUT', 10),
    'retry_attempts' => env('KRAYIN_API_RETRY_ATTEMPTS', 3),
    'retry_delay' => env('KRAYIN_API_RETRY_DELAY', 1000),
    'cache_ttl' => [
        'lead' => env('KRAYIN_CACHE_LEAD_TTL', 300),
        'pipeline' => env('KRAYIN_CACHE_PIPELINE_TTL', 3600),
        'stages' => env('KRAYIN_CACHE_STAGES_TTL', 86400),
    ],
    'security' => [
        'verify_ssl' => env('KRAYIN_VERIFY_SSL', true),
        'user_agent' => env('KRAYIN_USER_AGENT', 'Bridge-Service/2.1'),
        'max_redirects' => env('KRAYIN_MAX_REDIRECTS', 3),
    ],
    'endpoints' => [
        'leads' => env('KRAYIN_LEADS_ENDPOINT', '/api/leads'),
        'activities' => env('KRAYIN_ACTIVITIES_ENDPOINT', '/api/activities'),
        'pipelines' => env('KRAYIN_PIPELINES_ENDPOINT', '/api/pipelines'),
        'stages' => env('KRAYIN_STAGES_ENDPOINT', '/api/stages'),
        'health' => env('KRAYIN_HEALTH_ENDPOINT', '/health'),
    ],
],
```

## Error Handling

### Exception Types

The service throws `KrayinApiException` for all API-related errors:

```php
use App\Exceptions\KrayinApiException;

try {
    $result = $krayinService->createLead($data);
} catch (KrayinApiException $e) {
    // Check if the error is retryable
    if ($e->isRetryable()) {
        // Temporary error - could retry later
        Log::warning('Temporary API error', $e->toArray());
    } else {
        // Permanent error - fix the request
        Log::error('Permanent API error', $e->toArray());
    }
    
    // Get user-friendly error message
    $userMessage = $e->getUserMessage();
    
    // Get error code for programmatic handling
    $errorCode = $e->getErrorCode();
    
    // Get detailed context
    $context = $e->getContext();
}
```

### Error Codes

| Code | HTTP Status | Description | Action |
|------|-------------|-------------|---------|
| `BAD_REQUEST` | 400 | Invalid request data | Check request payload |
| `UNAUTHORIZED` | 401 | Authentication failed | Check API token |
| `FORBIDDEN` | 403 | Access denied | Check permissions |
| `NOT_FOUND` | 404 | Resource not found | Check resource ID |
| `CONFLICT` | 409 | Resource conflict | Handle duplicate |
| `VALIDATION_ERROR` | 422 | Validation failed | Fix validation errors |
| `RATE_LIMIT_EXCEEDED` | 429 | Rate limit exceeded | Wait and retry |
| `INTERNAL_SERVER_ERROR` | 500 | Server error | Retry later |
| `BAD_GATEWAY` | 502 | Gateway error | Retry later |
| `SERVICE_UNAVAILABLE` | 503 | Service unavailable | Retry later |

## Performance Optimization

### Caching Strategy

The service implements multiple caching levels:

1. **Lead Data**: 5-minute TTL with stale-while-revalidate
2. **Pipeline Data**: 1-hour TTL
3. **Stage Data**: 24-hour TTL
4. **Tag-based Invalidation**: Automatic cache clearing on updates

### Cache Warming

Preload frequently accessed data:

```php
$cachedService = app(CachedKrayinApiService::class);

// Warm cache with common data
$warmed = $cachedService->warmCache();

// Preload specific data
$preloaded = $cachedService->preloadFrequentData();
```

### Monitoring

Monitor service performance:

```php
$stats = $krayinService->getStats();
$cacheStats = $cachedService->getCacheStats();

// Log performance metrics
Log::info('Krayin API Performance', [
    'rate_limit_remaining' => $stats['rate_limit_remaining'],
    'circuit_breaker_failures' => $stats['circuit_breaker_failures'],
    'cache_hit_rate' => $cacheStats['hit_rate_percentage'],
]);
```

## Best Practices

1. **Always use try-catch blocks** around API calls
2. **Check error codes** for programmatic error handling
3. **Use the cached service** for read-heavy operations
4. **Monitor performance** with built-in statistics
5. **Warm caches** during low-traffic periods
6. **Handle rate limits** gracefully with user feedback
7. **Log errors** with full context for debugging

## Integration Examples

### In Controllers

```php
class LeadController extends Controller
{
    public function store(Request $request)
    {
        try {
            $krayinService = app(KrayinApiService::class);
            
            $leadData = $request->validated();
            $result = $krayinService->createLead($leadData);
            
            return response()->json([
                'success' => true,
                'lead_id' => $result['data']['id']
            ]);
            
        } catch (KrayinApiException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getUserMessage(),
                'error_code' => $e->getErrorCode()
            ], $e->getHttpStatusCode() ?: 500);
        }
    }
}
```

### In Jobs

```php
class ProcessLeadCreation implements ShouldQueue
{
    public function handle(KrayinApiService $krayinService)
    {
        try {
            $result = $krayinService->createLead($this->leadData);
            
            // Process successful creation
            Log::info('Lead created successfully', [
                'lead_id' => $result['data']['id']
            ]);
            
        } catch (KrayinApiException $e) {
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

This enhanced KrayinApiService provides a robust, production-ready foundation for integrating with the Krayin CRM API while maintaining high performance and reliability.
