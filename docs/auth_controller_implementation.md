# AuthController Implementation - Healthcare CRM Bridge v2.1

## Overview

This document describes the comprehensive AuthController implementation for the Healthcare CRM Bridge service, featuring security-enhanced JWT authentication, rate limiting, and LGPD compliance as specified in the architecture v2.1.

## Features Implemented

### ✅ Security Enhancements
- **JWT Token Authentication** with 60-minute expiration
- **Rate Limiting** per endpoint type (login, refresh, API, webhooks)
- **Request Validation** with comprehensive error handling
- **CORS Configuration** restricted to Krayin and Chatwoot origins
- **Security Middleware** for token validation and ability checking
- **Queue-based Audit Logging** for performance and compliance

### ✅ API Endpoints

#### Authentication Endpoints
- `POST /api/v1/auth/login` - User authentication with JWT token generation
- `POST /api/v1/auth/refresh` - Token refresh with validation
- `POST /api/v1/auth/logout` - Token invalidation and logout
- `GET /api/v1/auth/me` - Current user information
- `GET /api/v1/auth/validate` - Token validation

#### Protected API Routes
- `GET /api/v1/chatwoot/conversations/{lead_id}` - Chatwoot conversations
- `GET /api/v1/chatwoot/messages/{conversation_id}` - Chatwoot messages
- `GET /api/v1/ai/insights/{lead_id}` - AI insights for leads
- `POST /api/v1/lgpd/consent` - LGPD consent management
- `DELETE /api/v1/lgpd/data/{contact_id}` - Data deletion (LGPD)
- `GET /api/v1/lgpd/export/{contact_id}` - Data export (LGPD)

#### Webhook Endpoints
- `POST /api/v1/webhooks/chatwoot/conversation-created`
- `POST /api/v1/webhooks/chatwoot/message-created`
- `POST /api/v1/webhooks/chatwoot/conversation-status-changed`

## Architecture Components

### 1. AuthController (`app/Http/Controllers/Api/AuthController.php`)

**Features:**
- Comprehensive error handling with standardized responses
- Rate limiting integration
- JWT token management
- Audit logging for security events
- Performance monitoring with response time tracking

**Security Measures:**
- Input validation and sanitization
- Suspicious login pattern detection
- IP-based rate limiting
- Token blacklisting on logout
- Comprehensive audit trails

### 2. Request Validation Classes

#### LoginRequest (`app/Http/Requests/Api/Auth/LoginRequest.php`)
- Email format validation with DNS checking
- Strong password requirements (8+ chars, mixed case, numbers, special chars)
- Rate limiting integration
- XSS prevention
- Custom error messages

#### RefreshTokenRequest (`app/Http/Requests/Api/Auth/RefreshTokenRequest.php`)
- Token validation
- Device information sanitization
- Rate limiting for refresh attempts

### 3. Service Classes

#### AuthService (`app/Services/AuthService.php`)
- JWT token generation and validation
- User authentication with security checks
- Token refresh and invalidation
- User ability/permission management
- Suspicious login detection
- Cache-based token management

#### AuditService (`app/Services/AuditService.php`)
- Queue-based audit logging
- LGPD compliance logging
- Security event tracking
- Data access monitoring
- Performance-optimized logging

### 4. Middleware Components

#### ThrottleAuth (`app/Http/Middleware/ThrottleAuth.php`)
- Multi-tier rate limiting (per minute, per hour)
- IP and user-based limiting
- Configurable limits per endpoint type
- Rate limit headers in responses

#### JwtAuth (`app/Http/Middleware/JwtAuth.php`)
- JWT token validation
- User authentication
- Token blacklist checking
- Security event logging

#### CheckAbilities (`app/Http/Middleware/CheckAbilities.php`)
- Permission-based access control
- Role and ability validation
- Security audit logging

### 5. Queue Processing

#### ProcessAuditLog (`app/Jobs/ProcessAuditLog.php`)
- Asynchronous audit log processing
- Exponential backoff retry logic
- Dead letter queue for failed logs
- Performance monitoring

## Security Configuration

### Rate Limiting
```php
'rate_limits' => [
    'login' => ['5/min', '20/hour'],
    'refresh' => ['5/min', '20/hour'],
    'api' => ['60/min', '600/hour'],
    'webhook' => ['100/min', '1000/hour']
]
```

### JWT Configuration
```php
'jwt' => [
    'secret' => env('JWT_SECRET'),
    'algorithm' => 'HS256',
    'expiration' => 60, // minutes
    'issuer' => env('APP_URL'),
    'audience' => env('APP_NAME')
]
```

### CORS Security
- Restricted to Krayin and Chatwoot origins only
- Security headers for healthcare data protection
- LGPD compliance headers
- Rate limiting headers exposed

## API Response Format

### Success Response
```json
{
    "success": true,
    "access_token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "token_type": "Bearer",
    "expires_in": 3600,
    "expires_at": "2025-10-05T13:00:00Z",
    "abilities": ["conversations:read", "insights:read"],
    "user": {
        "id": 1,
        "name": "Dr. Maria Santos",
        "email": "user@clinic.com",
        "role": "admin"
    },
    "response_time_ms": 45.3
}
```

### Error Response
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

## Error Codes

| Code | HTTP | Description | Action |
|------|------|-------------|--------|
| `TOKEN_MISSING` | 401 | No token provided | Include Authorization header |
| `TOKEN_EXPIRED` | 401 | Token has expired | Refresh token |
| `INVALID_CREDENTIALS` | 401 | Wrong email/password | Check credentials |
| `ACCOUNT_DEACTIVATED` | 401 | User account disabled | Contact admin |
| `RATE_LIMIT_EXCEEDED` | 429 | Too many requests | Wait and retry |
| `VALIDATION_ERROR` | 422 | Request validation failed | Check request body |
| `FORBIDDEN` | 403 | Insufficient permissions | Check user abilities |

## LGPD Compliance Features

### Consent Management
- Explicit consent tracking
- Consent withdrawal support
- Version-controlled consent texts
- IP and user agent logging

### Data Protection
- Field-level encryption for health data
- Automated data retention enforcement
- Complete audit trail for all data access
- Data export API for portability

### Audit Logging
- All authentication events logged
- Data access monitoring
- Security event tracking
- Queue-based processing for performance

## Performance Optimizations

### Caching Strategy
- User abilities cached for 5 minutes
- JWT tokens cached for validation
- Rate limiting counters cached
- Audit logs processed asynchronously

### Database Optimization
- Compound indexes for audit logs
- Partitioned tables for large datasets
- Connection pooling with PgBouncer
- Read replicas for scaling

### Queue Processing
- High-priority queue for security events
- Normal queue for audit logs
- Dead letter queue for failed jobs
- Exponential backoff retry logic

## Installation and Setup

### 1. Install Dependencies
```bash
composer install
```

### 2. Environment Configuration
```env
# JWT Configuration
JWT_SECRET=your-jwt-secret-key
JWT_ALGORITHM=HS256
JWT_EXPIRATION=60

# Database
DB_CONNECTION=pgsql
DB_HOST=localhost
DB_PORT=5432
DB_DATABASE=bridge
DB_USERNAME=postgres
DB_PASSWORD=password

# Redis
REDIS_HOST=localhost
REDIS_PORT=6379
REDIS_PASSWORD=null

# CORS Origins
KRAYIN_URL=https://crm.yourdomain.com
CHATWOOT_URL=https://chat.yourdomain.com
```

### 3. Run Migrations
```bash
php artisan migrate
```

### 4. Start Queue Workers
```bash
php artisan queue:work redis --queue=audit-logs,audit-logs-high
```

## Testing

### Health Check
```bash
curl -X GET https://bridge.yourdomain.com/api/health
```

### Authentication Test
```bash
curl -X POST https://bridge.yourdomain.com/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "user@clinic.com",
    "password": "SecurePass123!"
  }'
```

### Token Validation
```bash
curl -X GET https://bridge.yourdomain.com/api/v1/auth/validate \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

## Security Considerations

### Production Deployment
1. Use strong JWT secrets (256-bit)
2. Enable HTTPS only
3. Configure proper CORS origins
4. Set up monitoring and alerting
5. Regular security audits
6. Database encryption at rest
7. Network security (VPC, firewalls)

### Monitoring
- Failed login attempts
- Rate limit violations
- Suspicious IP patterns
- Token usage patterns
- Audit log processing failures

## Compliance

### LGPD Requirements
- ✅ Explicit consent management
- ✅ Data retention policies
- ✅ Audit trail for all access
- ✅ Data export capabilities
- ✅ Right to erasure implementation
- ✅ Data processing transparency

### Healthcare Data Protection
- ✅ Field-level encryption
- ✅ Access logging
- ✅ Secure token management
- ✅ Network security
- ✅ Data minimization

## Support and Maintenance

### Log Monitoring
- Authentication failures
- Rate limit violations
- Security events
- Performance metrics
- Error rates

### Regular Tasks
- Audit log cleanup (retention policy)
- Token blacklist cleanup
- Security updates
- Performance monitoring
- Compliance audits

---

**Implementation Status**: ✅ Complete  
**Security Level**: Production Ready  
**Compliance**: LGPD Compliant  
**Performance**: Optimized for Scale  
**Last Updated**: October 14, 2025
