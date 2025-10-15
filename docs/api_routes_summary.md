# API Routes Summary - Bridge Service v2.1

**Project**: Healthcare CRM and LiveChat Platform  
**Version**: 2.1 (Security Enhanced)  
**Last Updated**: October 08, 2025

---

## Complete API Routes with Middleware Chains

### Base URL
```
https://bridge.yourdomain.com/api/v1
```

---

## 1. Authentication Routes (Public)

### POST /v1/auth/login
**Purpose**: User authentication with JWT token generation  
**Rate Limiting**: 5 req/min, 20 req/hour per IP  
**Middleware**: `throttle.auth:login`  
**Response**: JWT token with 60-minute expiration

### POST /v1/auth/refresh
**Purpose**: Refresh expired JWT tokens  
**Rate Limiting**: 5 req/min, 20 req/hour per IP  
**Middleware**: `throttle.auth:refresh`  
**Response**: New JWT token

### POST /v1/auth/logout
**Purpose**: Invalidate JWT token  
**Rate Limiting**: 60 req/min, 600 req/hour per user  
**Middleware**: `jwt.auth`  
**Response**: Success confirmation

### GET /v1/auth/me
**Purpose**: Get current user information  
**Rate Limiting**: 60 req/min, 600 req/hour per user  
**Middleware**: `jwt.auth`  
**Response**: User profile and abilities

### GET /v1/auth/validate
**Purpose**: Validate JWT token without authentication  
**Rate Limiting**: 60 req/min, 600 req/hour per IP  
**Middleware**: `throttle.auth:api`  
**Response**: Token validation status

---

## 2. Chatwoot Integration Routes (Protected)

### GET /v1/chatwoot/conversations/{lead_id}
**Purpose**: List conversations for a specific lead  
**Rate Limiting**: 60 req/min, 600 req/hour per user  
**Middleware**: `jwt.auth`, `throttle.auth:api`, `abilities:conversations:read`  
**Query Parameters**:
- `status` (optional): Filter by status
- `limit` (optional): Results per page (max 100)
- `page` (optional): Page number
- `sort` (optional): Sort field
- `order` (optional): Sort order

### GET /v1/chatwoot/messages/{conversation_id}
**Purpose**: List messages for a specific conversation  
**Rate Limiting**: 60 req/min, 600 req/hour per user  
**Middleware**: `jwt.auth`, `throttle.auth:api`, `abilities:conversations:read`  
**Query Parameters**:
- `limit` (optional): Messages per page (max 200)
- `before_id` (optional): Pagination cursor
- `after_id` (optional): Pagination cursor

---

## 3. AI Insights Routes (Protected)

### GET /v1/ai/insights/{lead_id}
**Purpose**: Get AI-generated insights for a lead  
**Rate Limiting**: 30 req/min, 300 req/hour per user (reduced due to computation cost)  
**Middleware**: `jwt.auth`, `throttle.auth:ai`, `abilities:insights:read`  
**Query Parameters**:
- `period` (optional): Time period for analysis (7d, 30d, 90d, all)
- `include_history` (optional): Include historical insights

**Response Features**:
- Performance score breakdown
- Engagement level analysis
- Actionable suggestions
- Historical trend data
- Caching: 1 hour TTL

---

## 4. LGPD Compliance Routes (Protected)

### POST /v1/lgpd/consent
**Purpose**: Record user consent for data processing  
**Rate Limiting**: 5 req/min, 20 req/hour per user  
**Middleware**: `jwt.auth`, `throttle.auth:lgpd`, `abilities:lgpd:write`  
**Request Body**:
```json
{
  "contact_id": 456,
  "consent_type": "health_data",
  "consent_granted": true,
  "ip_address": "192.168.1.1",
  "user_agent": "Mozilla/5.0..."
}
```

### GET /v1/lgpd/consent/{contact_id}
**Purpose**: Get consent status for a contact  
**Rate Limiting**: 5 req/min, 20 req/hour per user  
**Middleware**: `jwt.auth`, `throttle.auth:lgpd`, `abilities:lgpd:read`  
**Response**: Consent records and status

### DELETE /v1/lgpd/data/{contact_id}
**Purpose**: Complete data erasure (LGPD Right to Erasure)  
**Rate Limiting**: 5 req/min, 20 req/hour per user  
**Middleware**: `jwt.auth`, `throttle.auth:lgpd`, `abilities:admin:write`  
**Request Body**:
```json
{
  "confirmation": "DELETE_ALL_DATA",
  "reason": "User requested data deletion"
}
```

### GET /v1/lgpd/export/{contact_id}
**Purpose**: Data portability (LGPD Right to Data Portability)  
**Rate Limiting**: 5 req/min, 20 req/hour per user  
**Middleware**: `jwt.auth`, `throttle.auth:lgpd`, `abilities:lgpd:read`  
**Response**: Complete data export in JSON format

---

## 5. Data Export Routes (Admin Only)

### POST /v1/export/bulk
**Purpose**: Initiate bulk data export  
**Rate Limiting**: 5 req/min, 20 req/hour per user  
**Middleware**: `jwt.auth`, `throttle.auth:export`, `abilities:admin:write`  
**Response**: Export job ID and status

### GET /v1/export/status/{export_id}
**Purpose**: Check export job status  
**Rate Limiting**: 5 req/min, 20 req/hour per user  
**Middleware**: `jwt.auth`, `throttle.auth:export`, `abilities:admin:read`  
**Response**: Export progress and completion status

### GET /v1/export/download/{filename}
**Purpose**: Download completed export file  
**Rate Limiting**: 5 req/min, 20 req/hour per user  
**Middleware**: `jwt.auth`, `throttle.auth:export`, `abilities:admin:read`  
**Response**: File download with appropriate headers

### GET /v1/export/history
**Purpose**: List export history  
**Rate Limiting**: 5 req/min, 20 req/hour per user  
**Middleware**: `jwt.auth`, `throttle.auth:export`, `abilities:admin:read`  
**Response**: Paginated list of export jobs

### DELETE /v1/export/{export_id}
**Purpose**: Cancel pending export job  
**Rate Limiting**: 5 req/min, 20 req/hour per user  
**Middleware**: `jwt.auth`, `throttle.auth:export`, `abilities:admin:write`  
**Response**: Cancellation confirmation

---

## 6. Webhook Routes (Public with Signature Verification)

### POST /v1/webhooks/chatwoot/conversation-created
**Purpose**: Handle new conversation creation from Chatwoot  
**Rate Limiting**: 100 req/min, 1000 req/hour per IP  
**Middleware**: `throttle.auth:webhook`, `verify.chatwoot.signature`  
**Security Features**:
- HMAC-SHA256 signature verification
- Timestamp validation (5-minute tolerance)
- Replay attack prevention
- Payload size limits (1MB)
- Idempotency checking

**Required Headers**:
```http
Content-Type: application/json
X-Chatwoot-Signature: sha256=abc123...
X-Chatwoot-Timestamp: 1696512000
```

### POST /v1/webhooks/chatwoot/message-created
**Purpose**: Handle new message creation from Chatwoot  
**Rate Limiting**: 200 req/min, 2000 req/hour per IP  
**Middleware**: `throttle.auth:webhook`, `verify.chatwoot.signature`  
**Security**: Same as conversation-created

### POST /v1/webhooks/chatwoot/conversation-status-changed
**Purpose**: Handle conversation status changes from Chatwoot  
**Rate Limiting**: 100 req/min, 1000 req/hour per IP  
**Middleware**: `throttle.auth:webhook`, `verify.chatwoot.signature`  
**Security**: Same as conversation-created

### GET /v1/webhooks/chatwoot/test
**Purpose**: Test webhook connectivity  
**Rate Limiting**: 100 req/min, 1000 req/hour per IP  
**Middleware**: `throttle.auth:webhook`  
**Response**: Webhook service status

### GET /v1/webhooks/chatwoot/status
**Purpose**: Get webhook processing status  
**Rate Limiting**: 100 req/min, 1000 req/hour per IP  
**Middleware**: `throttle.auth:webhook`  
**Response**: Queue status and processing metrics

---

## 7. Health Check Routes (Public)

### GET /health
**Purpose**: Basic health check for load balancers  
**Rate Limiting**: 60 req/min, 600 req/hour per IP  
**Middleware**: None  
**Response**: Simple health status

### GET /health/detailed
**Purpose**: Detailed system health information  
**Rate Limiting**: 60 req/min, 600 req/hour per IP  
**Middleware**: `throttle.auth:api`  
**Response**: Comprehensive health metrics

### GET /health/readiness
**Purpose**: Kubernetes readiness probe  
**Rate Limiting**: 60 req/min, 600 req/hour per IP  
**Middleware**: `throttle.auth:api`  
**Response**: Service readiness status

### GET /health/liveness
**Purpose**: Kubernetes liveness probe  
**Rate Limiting**: 60 req/min, 600 req/hour per IP  
**Middleware**: `throttle.auth:api`  
**Response**: Service liveness status

### GET /health/metrics
**Purpose**: Prometheus metrics endpoint  
**Rate Limiting**: 60 req/min, 600 req/hour per IP  
**Middleware**: `throttle.auth:api`  
**Response**: Prometheus-formatted metrics

### GET /health/queues
**Purpose**: Queue system health and status  
**Rate Limiting**: 60 req/min, 600 req/hour per IP  
**Middleware**: `throttle.auth:api`  
**Response**: Queue metrics and backlog information

---

## Rate Limiting Summary

| Endpoint Type | Per Minute | Per Hour | Scope | Middleware |
|---------------|------------|----------|-------|------------|
| **Authentication** | 5 | 20 | Per IP | `throttle.auth:login|refresh` |
| **Data APIs** | 60 | 600 | Per User | `throttle.auth:api` |
| **AI Insights** | 30 | 300 | Per User | `throttle.auth:ai` |
| **LGPD Operations** | 5 | 20 | Per User | `throttle.auth:lgpd` |
| **Data Exports** | 5 | 20 | Per User | `throttle.auth:export` |
| **Webhooks** | 100 | 1000 | Per IP | `throttle.auth:webhook` |
| **Health Checks** | 60 | 600 | Per IP | `throttle.auth:api` |

---

## Security Features

### JWT Authentication
- **Expiration**: 60 minutes
- **Algorithm**: HS256
- **Refresh**: Automatic token refresh endpoint
- **Abilities**: Role-based access control

### Webhook Security
- **Signature**: HMAC-SHA256 with timestamp
- **Replay Protection**: 5-minute timestamp tolerance
- **Size Limits**: 1MB maximum payload
- **Idempotency**: 24-hour duplicate prevention

### Rate Limiting
- **Algorithm**: Token bucket with exponential backoff
- **Storage**: Redis-based distributed limiting
- **Headers**: Rate limit information in responses
- **Fallback**: In-memory limiting for Redis failures

### LGPD Compliance
- **Consent Management**: Explicit consent tracking
- **Data Export**: Complete data portability
- **Data Deletion**: Right to erasure implementation
- **Audit Trail**: Complete access logging

---

## Error Handling

### Standard Error Response
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
- `TOKEN_MISSING` (401): No JWT token provided
- `TOKEN_EXPIRED` (401): JWT token expired
- `INVALID_SIGNATURE` (403): Webhook signature invalid
- `TIMESTAMP_EXPIRED` (401): Webhook timestamp too old
- `FORBIDDEN` (403): Insufficient permissions
- `RATE_LIMIT_EXCEEDED` (429): Too many requests
- `PAYLOAD_TOO_LARGE` (413): Request body too large

---

## Performance Considerations

### Caching Strategy
- **JWT Tokens**: 60-minute TTL
- **AI Insights**: 1-hour TTL with stale-while-revalidate
- **Conversations**: 5-minute TTL with ETag support
- **Rate Limits**: Variable TTL based on window

### Database Optimization
- **Read Replicas**: Separate read/write connections
- **Connection Pooling**: PgBouncer for connection management
- **Indexes**: Compound indexes for common queries
- **Partitioning**: Monthly partitions for activity logs

### Queue Processing
- **Webhooks**: Asynchronous processing with retry logic
- **AI Insights**: Background calculation with caching
- **Data Exports**: Queue-based bulk operations
- **Audit Logs**: Non-blocking audit trail

---

**Document Version**: 2.1  
**Last Updated**: October 08, 2025  
**Total Routes**: 25 endpoints  
**Security Level**: Production-ready with comprehensive protection
