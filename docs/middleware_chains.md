# Middleware Chains Documentation - Bridge Service v2.1

**Project**: Healthcare CRM and LiveChat Platform  
**Version**: 2.1 (Security Enhanced)  
**Last Updated**: October 08, 2025

---

## Overview

This document provides comprehensive documentation of all middleware chains used in the Bridge Service API, including their security implications, rate limiting strategies, and performance considerations.

---

## Middleware Chain Architecture

### 1. Authentication Middleware Chains

#### JWT Authentication Chain
```php
['jwt.auth', 'throttle.auth:api']
```

**Purpose**: Secure API endpoints requiring user authentication  
**Security Features**:
- JWT token validation with 60-minute expiration
- User session validation
- Account status verification
- Ability-based authorization

**Rate Limiting**: 60 req/min, 600 req/hour per user

**Usage**: All protected API endpoints

---

### 2. Webhook Security Chains

#### Chatwoot Webhook Chain
```php
['throttle.auth:webhook', 'verify.chatwoot.signature']
```

**Purpose**: Secure webhook endpoints from Chatwoot  
**Security Features**:
- Signature verification with HMAC-SHA256
- Timestamp validation (5-minute tolerance)
- Replay attack prevention
- Payload size limits (1MB)
- Idempotency checking

**Rate Limiting**: 100 req/min, 1000 req/hour per IP

**Usage**: All Chatwoot webhook endpoints

---

### 3. Specialized Rate Limiting Chains

#### AI Insights Chain
```php
['throttle.auth:ai', 'abilities:insights:read']
```

**Purpose**: AI computation endpoints with reduced rate limits  
**Rate Limiting**: 30 req/min, 300 req/hour per user  
**Reasoning**: AI insights require significant computation resources

#### LGPD Compliance Chain
```php
['throttle.auth:lgpd', 'abilities:lgpd:read|lgpd:write|admin:write']
```

**Purpose**: LGPD compliance operations with strict rate limits  
**Rate Limiting**: 5 req/min, 20 req/hour per user  
**Reasoning**: Data exports and deletions are resource-intensive operations

#### Data Export Chain
```php
['throttle.auth:export', 'abilities:admin:read|admin:write']
```

**Purpose**: Administrative data export operations  
**Rate Limiting**: 5 req/min, 20 req/hour per user  
**Reasoning**: Bulk exports consume significant system resources

---

## Complete Middleware Configuration

### Kernel.php Middleware Aliases

```php
protected $middlewareAliases = [
    // Authentication
    'jwt.auth' => \App\Http\Middleware\JwtAuth::class,
    'auth' => \App\Http\Middleware\Authenticate::class,
    
    // Rate Limiting
    'throttle.auth' => \App\Http\Middleware\ThrottleAuth::class,
    'throttle.webhooks' => \App\Http\Middleware\ThrottleRequests::class,
    'throttle.api' => \App\Http\Middleware\ThrottleRequests::class,
    
    // Security
    'verify.chatwoot.signature' => \App\Http\Middleware\VerifyChatwootSignature::class,
    'abilities' => \App\Http\Middleware\CheckAbilities::class,
    
    // Audit & Compliance
    'audit.data.access' => \App\Http\Middleware\AuditDataAccess::class,
    
    // Caching
    'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
];
```

---

## Rate Limiting Strategy

### Endpoint-Specific Rate Limits

| Endpoint Type | Per Minute | Per Hour | Scope | Reasoning |
|---------------|------------|----------|-------|-----------|
| **Webhooks** | 100 | 1000 | Per IP | High volume, external source |
| **Data APIs** | 60 | 600 | Per User | Standard API usage |
| **AI Insights** | 30 | 300 | Per User | Computation intensive |
| **LGPD Exports** | 5 | 20 | Per User | Resource intensive |
| **Authentication** | 5 | 20 | Per IP | Security sensitive |
| **Health Checks** | 60 | 600 | Per IP | Monitoring needs |

### Rate Limiting Implementation

#### Token Bucket Algorithm
- **Algorithm**: Token bucket with exponential backoff
- **Storage**: Redis-based distributed rate limiting
- **Fallback**: In-memory rate limiting for Redis failures

#### Rate Limit Headers
All responses include rate limit information:
```http
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 45
X-RateLimit-Reset: 1696512600
X-RateLimit-Reset-After: 45
Retry-After: 60  (only when rate limited)
```

---

## Security Middleware Details

### 1. JWT Authentication (`JwtAuth`)

**Features**:
- Token validation with signature verification
- Expiration checking (60 minutes)
- User status validation
- Ability extraction and caching

**Error Responses**:
```json
{
  "success": false,
  "error": "Token expired",
  "error_code": "TOKEN_EXPIRED",
  "expires_at": "2025-10-05T12:00:00Z"
}
```

### 2. Webhook Signature Verification (`VerifyChatwootSignature`)

**Security Features**:
- HMAC-SHA256 signature verification
- Timestamp validation (5-minute window)
- Payload size limits (1MB maximum)
- Idempotency checking (24-hour window)
- Replay attack prevention

**Signature Calculation**:
```javascript
timestamp = Math.floor(Date.now() / 1000)
signedPayload = timestamp + '.' + payload
signature = HMAC-SHA256(signedPayload, secret)
```

**Error Responses**:
```json
{
  "success": false,
  "error": "Invalid signature",
  "error_code": "INVALID_SIGNATURE",
  "details": "Signature verification failed"
}
```

### 3. Ability Checking (`CheckAbilities`)

**Features**:
- Role-based access control
- Granular permission checking
- Ability inheritance
- Audit logging for denied access

**Required Abilities**:
- `conversations:read` - Read conversation data
- `insights:read` - Access AI insights
- `lgpd:read` - Read LGPD compliance data
- `lgpd:write` - Modify LGPD compliance data
- `admin:read` - Administrative read access
- `admin:write` - Administrative write access

---

## Performance Considerations

### Middleware Execution Order

1. **Global Middleware** (CORS, Trust Proxies, etc.)
2. **Rate Limiting** (Early rejection of excessive requests)
3. **Authentication** (JWT validation)
4. **Authorization** (Ability checking)
5. **Application Logic** (Controller execution)
6. **Response Processing** (Headers, caching, etc.)

### Caching Strategy

#### JWT Token Caching
- **Cache Key**: `jwt:user:{user_id}`
- **TTL**: 60 minutes
- **Invalidation**: On logout, ability changes

#### Rate Limit Caching
- **Cache Key**: `rate_limit:{type}:{identifier}`
- **TTL**: Variable (1 minute to 1 hour)
- **Storage**: Redis with fallback to memory

#### Webhook Idempotency
- **Cache Key**: `webhook_processed:{webhook_id}`
- **TTL**: 24 hours
- **Purpose**: Prevent duplicate processing

---

## Error Handling

### Standard Error Response Format

```json
{
  "success": false,
  "error": "Human-readable error message",
  "error_code": "SPECIFIC_ERROR_CODE",
  "details": "Additional technical details",
  "timestamp": "2025-10-05T12:00:00Z",
  "request_id": "req_abc123"
}
```

### Common Error Codes

| Code | HTTP | Description | Middleware |
|------|------|-------------|------------|
| `TOKEN_MISSING` | 401 | No JWT token provided | JwtAuth |
| `TOKEN_EXPIRED` | 401 | JWT token expired | JwtAuth |
| `INVALID_SIGNATURE` | 403 | Webhook signature invalid | VerifyChatwootSignature |
| `TIMESTAMP_EXPIRED` | 401 | Webhook timestamp too old | VerifyChatwootSignature |
| `FORBIDDEN` | 403 | Insufficient permissions | CheckAbilities |
| `RATE_LIMIT_EXCEEDED` | 429 | Too many requests | ThrottleAuth |
| `PAYLOAD_TOO_LARGE` | 413 | Request body too large | VerifyChatwootSignature |

---

## Monitoring and Observability

### Middleware Metrics

#### Rate Limiting Metrics
- Requests per minute by endpoint type
- Rate limit violations by IP/user
- Cache hit/miss ratios
- Queue sizes and processing times

#### Security Metrics
- Failed authentication attempts
- Invalid webhook signatures
- Permission denied events
- Suspicious activity patterns

#### Performance Metrics
- Middleware execution times
- Database query counts
- Cache performance
- Memory usage patterns

### Logging Strategy

#### Security Events
```php
Log::warning('Rate limit exceeded', [
    'type' => $type,
    'ip' => $request->ip(),
    'user_id' => $user?->id,
    'endpoint' => $request->path()
]);
```

#### Performance Events
```php
Log::info('Middleware execution time', [
    'middleware' => 'JwtAuth',
    'duration_ms' => $executionTime,
    'user_id' => $user?->id
]);
```

---

## Configuration Examples

### Production Configuration

```php
// config/rate-limiting.php
return [
    'webhook' => [
        'max_attempts' => 100,
        'decay_minutes' => 1,
        'window' => '1min'
    ],
    'ai_insights' => [
        'max_attempts' => 30,
        'decay_minutes' => 1,
        'window' => '1min'
    ]
];
```

### Development Configuration

```php
// config/rate-limiting.php (development)
return [
    'webhook' => [
        'max_attempts' => 1000, // Higher for testing
        'decay_minutes' => 1,
        'window' => '1min'
    ]
];
```

---

## Testing Middleware

### Unit Tests

```php
public function test_jwt_auth_middleware()
{
    $response = $this->postJson('/api/v1/chatwoot/conversations/1', [])
        ->assertStatus(401)
        ->assertJson([
            'success' => false,
            'error_code' => 'TOKEN_MISSING'
        ]);
}
```

### Integration Tests

```php
public function test_rate_limiting_webhook()
{
    // Test rate limiting for webhook endpoints
    for ($i = 0; $i < 101; $i++) {
        $response = $this->postJson('/api/v1/webhooks/chatwoot/conversation-created', []);
        
        if ($i < 100) {
            $response->assertStatus(200);
        } else {
            $response->assertStatus(429);
        }
    }
}
```

---

## Troubleshooting

### Common Issues

#### Rate Limiting Not Working
1. Check Redis connection
2. Verify middleware registration
3. Check cache configuration
4. Review rate limit keys

#### JWT Authentication Failing
1. Verify JWT secret configuration
2. Check token expiration
3. Validate user status
4. Review ability assignments

#### Webhook Signature Verification
1. Verify webhook secret configuration
2. Check timestamp synchronization
3. Validate signature calculation
4. Review payload format

### Debug Commands

```bash
# Check rate limit status
php artisan cache:forget rate_limit:webhook:192.168.1.1:1min

# Clear JWT cache
php artisan cache:forget jwt:user:1

# Test webhook signature
php artisan webhook:test-signature
```

---

**Document Version**: 2.1  
**Last Updated**: October 08, 2025  
**Maintainer**: Bridge Service Team
