# ChatwootWebhookController Implementation - Healthcare CRM Bridge v2.1

## Overview

This document describes the comprehensive ChatwootWebhookController implementation for the Healthcare CRM Bridge service, featuring security-enhanced webhook processing, signature verification, and queue-based processing as specified in the architecture v2.1.

## Features Implemented

### ✅ Security Enhancements
- **Webhook Signature Verification** with replay attack prevention
- **Rate Limiting** per webhook type (100-200 req/min per IP)
- **Request Validation** with comprehensive error handling
- **Idempotency Protection** via webhook ID caching
- **Payload Size Limits** (1MB maximum)
- **Timestamp Validation** (5-minute tolerance)

### ✅ Webhook Endpoints

#### Core Webhook Endpoints
- `POST /api/v1/webhooks/chatwoot/conversation-created` - Process new conversations
- `POST /api/v1/webhooks/chatwoot/message-created` - Process new messages
- `POST /api/v1/webhooks/chatwoot/conversation-status-changed` - Process status changes

#### Utility Endpoints
- `GET /api/v1/webhooks/chatwoot/test` - Webhook endpoint testing
- `GET /api/v1/webhooks/chatwoot/status` - Check webhook processing status

### ✅ Queue-Based Processing
- **High Priority Queue** for conversation and status changes
- **Normal Priority Queue** for message processing
- **Exponential Backoff** retry logic (1min, 2min, 5min, 10min, 30min)
- **Dead Letter Queue** for failed webhooks
- **Asynchronous Processing** for better performance

## Architecture Components

### 1. ChatwootWebhookController (`app/Http/Controllers/Api/ChatwootWebhookController.php`)

**Features:**
- Immediate webhook acknowledgment (200 OK)
- Queue-based processing for performance
- Comprehensive error handling and logging
- Security event tracking
- Response time monitoring

**Security Measures:**
- Signature verification via middleware
- Rate limiting per IP address
- Idempotency protection
- Payload size validation
- Replay attack prevention

### 2. Request Validation Classes

#### ConversationCreatedRequest (`app/Http/Requests/Api/Webhook/ConversationCreatedRequest.php`)
- Event type validation (`conversation_created`)
- Contact information validation
- Conversation metadata validation
- Assignee and team validation
- XSS prevention and input sanitization

#### MessageCreatedRequest (`app/Http/Requests/Api/Webhook/MessageCreatedRequest.php`)
- Event type validation (`message_created`)
- Message content validation (10,000 char limit)
- Sender information validation
- Attachment validation
- Content type validation

#### ConversationStatusChangedRequest (`app/Http/Requests/Api/Webhook/ConversationStatusChangedRequest.php`)
- Event type validation (`conversation_status_changed`)
- Status transition validation
- Assignee and team validation
- Label validation
- Change tracking

### 3. Security Middleware

#### VerifyChatwootSignature (`app/Http/Middleware/VerifyChatwootSignature.php`)
- **Payload Size Validation** (1MB limit)
- **Timestamp Validation** (5-minute tolerance)
- **Signature Verification** with HMAC-SHA256
- **Replay Attack Prevention** via timestamp checking
- **Idempotency Protection** via webhook ID caching
- **Comprehensive Logging** for security monitoring

**Security Features:**
- Prevents DoS attacks via payload size limits
- Prevents replay attacks via timestamp validation
- Ensures webhook authenticity via signature verification
- Prevents duplicate processing via idempotency checks

### 4. Queue Processing Jobs

#### ProcessConversationCreated (`app/Jobs/ProcessConversationCreated.php`)
- Creates leads in Krayin CRM
- Establishes contact mappings
- Creates conversation mappings
- Handles existing contact scenarios
- Comprehensive error handling and retry logic

#### ProcessMessageCreated (`app/Jobs/ProcessMessageCreated.php`)
- Creates activities in Krayin CRM
- Establishes activity mappings
- Updates conversation statistics
- Handles message content processing
- Attachment and media handling

#### ProcessConversationStatusChanged (`app/Jobs/ProcessConversationStatusChanged.php`)
- Updates lead stages in Krayin CRM
- Maps Chatwoot statuses to Krayin stages
- Logs stage change history
- Handles status transitions
- Comprehensive audit trailing

### 5. Service Classes

#### WebhookService (`app/Services/WebhookService.php`)
- Webhook processing logic
- Status checking and monitoring
- Failed webhook retry functionality
- Statistics and analytics
- Performance monitoring

## Security Configuration

### Rate Limiting
```php
'webhook_limits' => [
    'conversation_created' => ['100/min', '1000/hour'],
    'message_created' => ['200/min', '2000/hour'],
    'conversation_status_changed' => ['100/min', '1000/hour']
]
```

### Signature Verification
```php
'webhook_security' => [
    'max_payload_size' => 1048576, // 1MB
    'timestamp_tolerance' => 300,   // 5 minutes
    'signature_algorithm' => 'sha256',
    'idempotency_ttl' => 86400     // 24 hours
]
```

### Queue Configuration
```php
'webhook_queues' => [
    'high_priority' => ['conversation_created', 'conversation_status_changed'],
    'normal_priority' => ['message_created'],
    'retry_attempts' => 5,
    'backoff_strategy' => 'exponential'
]
```

## API Response Format

### Success Response
```json
{
    "success": true,
    "webhook_id": "123",
    "queued_at": "2025-10-05T12:00:01Z",
    "processing_status": "queued",
    "estimated_processing_time_seconds": 5,
    "response_time_ms": 45.3
}
```

### Error Response
```json
{
    "success": false,
    "error": "Webhook processing failed",
    "error_code": "WEBHOOK_PROCESSING_ERROR",
    "webhook_id": "123",
    "timestamp": "2025-10-05T12:00:00Z"
}
```

### Security Error Responses

#### Payload Too Large (413)
```json
{
    "success": false,
    "error": "Payload too large",
    "error_code": "PAYLOAD_TOO_LARGE",
    "max_size_bytes": 1048576,
    "received_bytes": 2000000
}
```

#### Invalid Signature (403)
```json
{
    "success": false,
    "error": "Invalid signature",
    "error_code": "INVALID_SIGNATURE",
    "details": "Signature verification failed"
}
```

#### Timestamp Expired (401)
```json
{
    "success": false,
    "error": "Timestamp expired",
    "error_code": "TIMESTAMP_EXPIRED",
    "details": "Webhook timestamp is older than 5 minutes"
}
```

## Webhook Processing Flow

### 1. Webhook Reception
```
Chatwoot → Bridge Service
├── Signature Verification
├── Timestamp Validation
├── Payload Size Check
├── Rate Limiting Check
└── Idempotency Check
```

### 2. Queue Processing
```
Webhook Controller → Queue Job
├── High Priority: conversation_created, status_changed
├── Normal Priority: message_created
├── Exponential Backoff Retry
└── Dead Letter Queue (on failure)
```

### 3. Data Processing
```
Queue Job → Krayin API
├── Lead Creation/Update
├── Contact Mapping
├── Activity Creation
├── Stage Updates
└── Audit Logging
```

## Error Handling

### Retry Logic
- **Exponential Backoff**: 1min, 2min, 5min, 10min, 30min
- **Maximum Attempts**: 5 retries
- **Timeout**: 120 seconds per attempt
- **Dead Letter Queue**: Failed webhooks stored for manual review

### Error Categories
1. **Validation Errors** (422) - Invalid payload format
2. **Security Errors** (401/403) - Authentication/authorization failures
3. **Rate Limit Errors** (429) - Too many requests
4. **Processing Errors** (500) - Internal service failures
5. **Timeout Errors** (504) - Service unavailable

## Monitoring and Observability

### Logging
- **Webhook Reception**: All incoming webhooks logged
- **Security Events**: Failed signatures, rate limits, suspicious activity
- **Processing Events**: Queue dispatch, job execution, completion
- **Error Events**: Failures, retries, dead letter queue entries

### Metrics
- **Webhook Volume**: Requests per minute/hour
- **Success Rate**: Processing success percentage
- **Error Rate**: Failure percentage by error type
- **Processing Time**: Average queue processing time
- **Queue Depth**: Pending jobs in queues

### Health Checks
- **Queue Health**: Monitor queue depth and processing rate
- **Database Health**: Check mapping table integrity
- **API Health**: Verify Krayin API connectivity
- **Redis Health**: Check cache and queue connectivity

## Performance Optimizations

### Caching Strategy
- **Webhook Idempotency**: 24-hour TTL for processed webhooks
- **Contact Mappings**: Cached for quick lookups
- **Stage Mappings**: Cached configuration
- **Rate Limiting**: Redis-based counters

### Database Optimization
- **Compound Indexes**: Optimized for webhook queries
- **Partitioning**: Time-based partitioning for large tables
- **Connection Pooling**: PgBouncer for database connections
- **Read Replicas**: Separate read/write connections

### Queue Optimization
- **Priority Queues**: High-priority for critical webhooks
- **Batch Processing**: Group similar operations
- **Dead Letter Queue**: Manual review of failed webhooks
- **Monitoring**: Queue depth and processing metrics

## LGPD Compliance Features

### Data Protection
- **Audit Logging**: All webhook processing logged
- **Data Minimization**: Only necessary data stored
- **Retention Policies**: Automated cleanup of old data
- **Access Logging**: Who accessed what data when

### Consent Management
- **Explicit Consent**: Required for data processing
- **Consent Tracking**: All consent events logged
- **Withdrawal Support**: Consent withdrawal handling
- **Data Export**: Complete data export capabilities

## Testing and Validation

### Webhook Testing
```bash
# Test webhook endpoint
curl -X GET https://bridge.yourdomain.com/api/v1/webhooks/chatwoot/test

# Test webhook status
curl -X GET "https://bridge.yourdomain.com/api/v1/webhooks/chatwoot/status?webhook_id=123"
```

### Signature Testing
```bash
# Test webhook with signature
curl -X POST https://bridge.yourdomain.com/api/v1/webhooks/chatwoot/conversation-created \
  -H "Content-Type: application/json" \
  -H "X-Chatwoot-Signature: sha256=..." \
  -H "X-Chatwoot-Timestamp: $(date +%s)" \
  -d @webhook-payload.json
```

### Load Testing
- **Rate Limit Testing**: Verify rate limiting works
- **Payload Size Testing**: Test 1MB limit enforcement
- **Concurrent Processing**: Test queue handling
- **Error Recovery**: Test retry and dead letter queue

## Deployment Considerations

### Production Setup
1. **Redis Configuration**: High availability Redis cluster
2. **Queue Workers**: Multiple workers for different priorities
3. **Database**: Read replicas and connection pooling
4. **Monitoring**: Comprehensive logging and alerting
5. **Security**: Network security and access controls

### Environment Configuration
```env
# Webhook Security
CHATWOOT_WEBHOOK_SECRET=your-webhook-secret
WEBHOOK_MAX_PAYLOAD_SIZE=1048576
WEBHOOK_TIMESTAMP_TOLERANCE=300

# Queue Configuration
QUEUE_CONNECTION=redis
REDIS_HOST=localhost
REDIS_PORT=6379

# Krayin API
KRAYIN_API_URL=https://crm.yourdomain.com
KRAYIN_API_TOKEN=your-api-token
```

### Monitoring Setup
- **Log Aggregation**: Centralized logging system
- **Metrics Collection**: Performance and error metrics
- **Alerting**: Automated alerts for failures
- **Dashboards**: Real-time monitoring dashboards

## Troubleshooting

### Common Issues
1. **Signature Verification Failures**: Check webhook secret configuration
2. **Rate Limit Exceeded**: Monitor request patterns and adjust limits
3. **Queue Backlog**: Scale queue workers or optimize processing
4. **Database Timeouts**: Check connection pooling and query optimization
5. **API Failures**: Monitor Krayin API health and retry logic

### Debug Commands
```bash
# Check queue status
php artisan queue:work --queue=webhooks-high,webhooks-normal

# Monitor failed webhooks
php artisan tinker
>>> DB::table('failed_webhooks')->get();

# Check webhook processing status
curl -X GET "https://bridge.yourdomain.com/api/v1/webhooks/chatwoot/status?webhook_id=123"
```

## Security Best Practices

### Webhook Security
- **Signature Verification**: Always verify webhook signatures
- **Timestamp Validation**: Prevent replay attacks
- **Rate Limiting**: Protect against abuse
- **Payload Validation**: Sanitize and validate all inputs
- **Idempotency**: Prevent duplicate processing

### Network Security
- **HTTPS Only**: All webhook endpoints use HTTPS
- **IP Whitelisting**: Restrict access to known Chatwoot IPs
- **Firewall Rules**: Block unnecessary ports and protocols
- **DDoS Protection**: Rate limiting and payload size limits

### Data Security
- **Encryption**: Encrypt sensitive data at rest and in transit
- **Access Control**: Role-based access to webhook data
- **Audit Logging**: Complete audit trail for compliance
- **Data Retention**: Automated cleanup of old data

---

**Implementation Status**: ✅ Complete  
**Security Level**: Production Ready  
**Compliance**: LGPD Compliant  
**Performance**: Optimized for Scale  
**Last Updated**: October 14, 2025
