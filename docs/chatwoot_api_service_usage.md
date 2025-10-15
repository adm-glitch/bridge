# ChatwootApiService Usage Guide

## Overview

The enhanced ChatwootApiService provides a robust, production-ready interface to the Chatwoot API with comprehensive error handling, retry logic, caching, security measures, and webhook signature verification.

## Features

- **Exponential Backoff Retry Logic**: Automatically retries failed requests with intelligent backoff
- **Circuit Breaker Pattern**: Prevents cascading failures by temporarily stopping requests when the API is down
- **Rate Limiting**: Built-in protection against API rate limits
- **Intelligent Caching**: Multi-level caching with TTL and invalidation strategies
- **Security Measures**: SSL verification, request sanitization, security headers, and webhook signature verification
- **Comprehensive Error Handling**: Detailed error information with retry recommendations
- **Performance Monitoring**: Built-in statistics and health checks
- **Webhook Integration**: Secure webhook signature verification and cache invalidation

## Basic Usage

### Service Registration

The service is automatically registered in Laravel's service container. You can inject it into your controllers or use it directly:

```php
use App\Services\ChatwootApiService;
use App\Services\CachedChatwootApiService;

// Basic service
$chatwootService = app(ChatwootApiService::class);

// Cached service with advanced caching
$cachedService = app(CachedChatwootApiService::class);
```

### Getting Conversation Information

```php
use App\Services\ChatwootApiService;
use App\Exceptions\ChatwootApiException;

try {
    $chatwootService = app(ChatwootApiService::class);
    
    $conversation = $chatwootService->getConversation(123);
    
    echo "Conversation Status: " . $conversation['data']['status'];
    echo "Contact Name: " . $conversation['data']['contact']['name'];
    
} catch (ChatwootApiException $e) {
    // Handle API-specific errors
    if ($e->isRetryable()) {
        echo "Temporary error, will retry: " . $e->getUserMessage();
    } else {
        echo "Permanent error: " . $e->getUserMessage();
    }
    
    // Log detailed error information
    Log::error('Chatwoot API error', $e->toArray());
}
```

### Getting Messages with Caching

```php
use App\Services\CachedChatwootApiService;

$cachedService = app(CachedChatwootApiService::class);

try {
    // This will use cache if available, otherwise fetch from API
    $messages = $cachedService->getMessages(123, [
        'limit' => 50,
        'before_id' => 1000
    ]);
    
    foreach ($messages['data'] as $message) {
        echo "Message: " . $message['content'];
        echo "From: " . $message['sender']['name'];
    }
    
} catch (ChatwootApiException $e) {
    echo "Error fetching messages: " . $e->getUserMessage();
}
```

### Creating a Contact

```php
try {
    $contactData = [
        'name' => 'João Silva',
        'email' => 'joao@example.com',
        'phone_number' => '+5511999999999',
        'account_id' => 1
    ];
    
    $result = $chatwootService->createContact($contactData);
    
    echo "Contact created with ID: " . $result['data']['id'];
    
} catch (ChatwootApiException $e) {
    echo "Error creating contact: " . $e->getUserMessage();
}
```

### Sending a Message

```php
try {
    $messageData = [
        'content' => 'Olá! Como posso ajudar?',
        'message_type' => 'outgoing',
        'content_type' => 'text'
    ];
    
    $result = $chatwootService->createMessage(123, $messageData);
    
    echo "Message sent with ID: " . $result['data']['id'];
    
} catch (ChatwootApiException $e) {
    echo "Error sending message: " . $e->getUserMessage();
}
```

## Webhook Integration

### Webhook Signature Verification

```php
use App\Services\ChatwootApiService;

$chatwootService = app(ChatwootApiService::class);

// Verify webhook signature
$payload = $request->getContent();
$signature = $request->header('X-Chatwoot-Signature');
$timestamp = $request->header('X-Chatwoot-Timestamp');

if ($chatwootService->verifyWebhookSignature($payload, $signature, $timestamp)) {
    // Process webhook
    $data = json_decode($payload, true);
    echo "Webhook verified successfully";
} else {
    return response()->json(['error' => 'Invalid signature'], 403);
}
```

### Handling Webhook Events with Cache Invalidation

```php
use App\Services\CachedChatwootApiService;

$cachedService = app(CachedChatwootApiService::class);

// Handle webhook event
$event = $request->input('event');
$data = $request->all();

// This will automatically invalidate relevant caches
$cachedService->handleWebhookEvent($event, $data);

switch ($event) {
    case 'conversation_created':
        // Handle conversation creation
        break;
    case 'message_created':
        // Handle new message
        break;
    case 'conversation_status_changed':
        // Handle status change
        break;
}
```

## Advanced Features

### Health Monitoring

```php
// Check API health
$health = $chatwootService->healthCheck();

if ($health['status'] === 'ok') {
    echo "API is healthy (Response time: {$health['response_time_ms']}ms)";
} else {
    echo "API is unhealthy: " . $health['message'];
}
```

### Service Statistics

```php
// Get service performance statistics
$stats = $chatwootService->getStats();

echo "Rate limit remaining: " . $stats['rate_limit_remaining'];
echo "Circuit breaker failures: " . $stats['circuit_breaker_failures'];
```

### Cache Management (CachedChatwootApiService)

```php
$cachedService = app(CachedChatwootApiService::class);

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
# Chatwoot API Configuration
CHATWOOT_URL=https://chatwoot.yourdomain.com
CHATWOOT_API_TOKEN=your_api_token_here
CHATWOOT_API_TIMEOUT=10
CHATWOOT_API_RETRY_ATTEMPTS=3
CHATWOOT_API_RETRY_DELAY=1000

# Cache TTL Settings
CHATWOOT_CACHE_CONVERSATION_TTL=300
CHATWOOT_CACHE_MESSAGES_TTL=60
CHATWOOT_CACHE_CONTACT_TTL=600
CHATWOOT_CACHE_ACCOUNT_TTL=3600

# Security Settings
CHATWOOT_VERIFY_SSL=true
CHATWOOT_USER_AGENT=Bridge-Service/2.1
CHATWOOT_MAX_REDIRECTS=3

# Webhook Security
CHATWOOT_WEBHOOK_SECRET=your_webhook_secret_here
CHATWOOT_WEBHOOK_TIMESTAMP_ENABLED=true
WEBHOOK_MAX_PAYLOAD_SIZE=1048576
WEBHOOK_TIMESTAMP_TOLERANCE=300
WEBHOOK_IDEMPOTENCY_TTL=86400
```

### Service Configuration

The service configuration is in `config/services.php`:

```php
'chatwoot' => [
    'base_url' => env('CHATWOOT_URL', 'https://chatwoot.yourdomain.com'),
    'api_token' => env('CHATWOOT_API_TOKEN'),
    'timeout' => env('CHATWOOT_API_TIMEOUT', 10),
    'retry_attempts' => env('CHATWOOT_API_RETRY_ATTEMPTS', 3),
    'retry_delay' => env('CHATWOOT_API_RETRY_DELAY', 1000),
    'cache_ttl' => [
        'conversation' => env('CHATWOOT_CACHE_CONVERSATION_TTL', 300),
        'messages' => env('CHATWOOT_CACHE_MESSAGES_TTL', 60),
        'contact' => env('CHATWOOT_CACHE_CONTACT_TTL', 600),
        'account' => env('CHATWOOT_CACHE_ACCOUNT_TTL', 3600),
    ],
    'security' => [
        'verify_ssl' => env('CHATWOOT_VERIFY_SSL', true),
        'user_agent' => env('CHATWOOT_USER_AGENT', 'Bridge-Service/2.1'),
        'max_redirects' => env('CHATWOOT_MAX_REDIRECTS', 3),
    ],
    'webhook_security' => [
        'timestamp_enabled' => env('CHATWOOT_WEBHOOK_TIMESTAMP_ENABLED', true),
        'max_payload_size' => env('WEBHOOK_MAX_PAYLOAD_SIZE', 1048576),
        'timestamp_tolerance' => env('WEBHOOK_TIMESTAMP_TOLERANCE', 300),
        'idempotency_ttl' => env('WEBHOOK_IDEMPOTENCY_TTL', 86400),
    ],
    'endpoints' => [
        'conversations' => env('CHATWOOT_CONVERSATIONS_ENDPOINT', '/api/v1/conversations'),
        'messages' => env('CHATWOOT_MESSAGES_ENDPOINT', '/api/v1/messages'),
        'contacts' => env('CHATWOOT_CONTACTS_ENDPOINT', '/api/v1/contacts'),
        'accounts' => env('CHATWOOT_ACCOUNTS_ENDPOINT', '/api/v1/accounts'),
    ],
],
```

## Error Handling

### Exception Types

The service throws `ChatwootApiException` for all API-related errors:

```php
use App\Exceptions\ChatwootApiException;

try {
    $result = $chatwootService->getConversation(123);
} catch (ChatwootApiException $e) {
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

1. **Conversation Data**: 5-minute TTL with stale-while-revalidate
2. **Messages Data**: 1-minute TTL (shorter due to real-time nature)
3. **Contact Data**: 10-minute TTL
4. **Account Data**: 1-hour TTL
5. **Tag-based Invalidation**: Automatic cache clearing on updates

### Cache Warming

Preload frequently accessed data:

```php
$cachedService = app(CachedChatwootApiService::class);

// Warm cache with common data
$warmed = $cachedService->warmCache();

// Preload specific data
$preloaded = $cachedService->preloadFrequentData();
```

### Webhook-Aware Cache Invalidation

The cached service automatically handles webhook events to invalidate relevant caches:

```php
// Webhook events automatically trigger cache invalidation
$cachedService->handleWebhookEvent('conversation_created', $data);
$cachedService->handleWebhookEvent('message_created', $data);
$cachedService->handleWebhookEvent('contact_updated', $data);
```

### Monitoring

Monitor service performance:

```php
$stats = $chatwootService->getStats();
$cacheStats = $cachedService->getCacheStats();

// Log performance metrics
Log::info('Chatwoot API Performance', [
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
8. **Verify webhook signatures** for security
9. **Use webhook-aware cache invalidation** for real-time updates

## Integration Examples

### In Controllers

```php
class ChatwootController extends Controller
{
    public function getConversation(Request $request, int $conversationId)
    {
        try {
            $chatwootService = app(ChatwootApiService::class);
            
            $conversation = $chatwootService->getConversation($conversationId);
            
            return response()->json([
                'success' => true,
                'conversation' => $conversation['data']
            ]);
            
        } catch (ChatwootApiException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getUserMessage(),
                'error_code' => $e->getErrorCode()
            ], $e->getHttpStatusCode() ?: 500);
        }
    }
}
```

### In Webhook Handlers

```php
class ChatwootWebhookController extends Controller
{
    public function handleWebhook(Request $request)
    {
        $chatwootService = app(ChatwootApiService::class);
        $cachedService = app(CachedChatwootApiService::class);
        
        // Verify signature
        $payload = $request->getContent();
        $signature = $request->header('X-Chatwoot-Signature');
        $timestamp = $request->header('X-Chatwoot-Timestamp');
        
        if (!$chatwootService->verifyWebhookSignature($payload, $signature, $timestamp)) {
            return response()->json(['error' => 'Invalid signature'], 403);
        }
        
        // Process webhook
        $data = json_decode($payload, true);
        $event = $data['event'];
        
        // Handle cache invalidation
        $cachedService->handleWebhookEvent($event, $data);
        
        // Process the webhook event
        // ... your webhook processing logic
        
        return response()->json(['success' => true]);
    }
}
```

### In Jobs

```php
class ProcessChatwootMessage implements ShouldQueue
{
    public function handle(ChatwootApiService $chatwootService)
    {
        try {
            $result = $chatwootService->createMessage($this->conversationId, $this->messageData);
            
            // Process successful message creation
            Log::info('Message created successfully', [
                'message_id' => $result['data']['id']
            ]);
            
        } catch (ChatwootApiException $e) {
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

This enhanced ChatwootApiService provides a robust, production-ready foundation for integrating with the Chatwoot API while maintaining high performance, security, and reliability with comprehensive webhook support.
