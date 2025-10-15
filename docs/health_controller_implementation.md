# HealthController Implementation Documentation

## Overview

The `HealthController` provides comprehensive health monitoring and system status endpoints for the Healthcare CRM Bridge Service. It implements production-grade health checks, Kubernetes-compatible probes, and detailed system metrics as specified in the architecture v2.1.

## Features

### 🔍 **Health Check Endpoints**
- **Basic Health**: Simple status check for load balancers
- **Detailed Health**: Comprehensive system component checks
- **Readiness Probe**: Kubernetes readiness check
- **Liveness Probe**: Kubernetes liveness check
- **Metrics**: System performance and resource usage
- **Queue Status**: Queue monitoring and backlog detection

### 🛡️ **Security Features**
- Rate limiting on detailed endpoints
- Input validation and sanitization
- XSS prevention in request processing
- Comprehensive error handling and logging

### 📊 **Monitoring Capabilities**
- Database connection and performance metrics
- Redis cache and queue status
- External API health (Krayin, Chatwoot)
- Memory usage and system resources
- Queue backlog detection and alerts

## API Endpoints

### 1. Basic Health Check
```http
GET /api/health
```

**Response**:
```json
{
  "status": "healthy",
  "timestamp": "2025-10-14T13:00:00Z",
  "service": "Healthcare CRM Bridge",
  "version": "2.1",
  "uptime": "up 5 days, 2 hours",
  "response_time_ms": 12.5
}
```

### 2. Detailed Health Check
```http
GET /api/health/detailed?include_metrics=true&include_queues=true
```

**Response**:
```json
{
  "status": "healthy",
  "timestamp": "2025-10-14T13:00:00Z",
  "service": "Healthcare CRM Bridge",
  "version": "2.1",
  "checks": {
    "database": {
      "status": "ok",
      "message": "Database connected",
      "response_time_ms": 5.2,
      "timestamp": "2025-10-14T13:00:00Z"
    },
    "redis": {
      "status": "ok",
      "message": "Redis connected",
      "response_time_ms": 2.1
    },
    "krayin_api": {
      "status": "ok",
      "message": "Krayin API accessible",
      "response_time_ms": 45.3,
      "status_code": 200
    },
    "chatwoot_api": {
      "status": "ok",
      "message": "Chatwoot API accessible",
      "response_time_ms": 38.7,
      "status_code": 200
    },
    "queue": {
      "status": "ok",
      "message": "Queue healthy",
      "queue_size": 25,
      "max_size": 1000
    }
  },
  "metrics": {
    "memory": {
      "usage_bytes": 67108864,
      "peak_bytes": 134217728,
      "limit": "256M"
    },
    "database": {
      "active_connections": 5,
      "database_size": "125 MB",
      "status": "ok"
    },
    "redis": {
      "used_memory": "45.2M",
      "connected_clients": 3,
      "total_commands_processed": 125000,
      "keyspace_hits": 120000,
      "keyspace_misses": 5000,
      "status": "ok"
    },
    "cache": {
      "hits": 120000,
      "misses": 5000,
      "hit_rate": 96.0,
      "status": "ok"
    },
    "queue": {
      "total_jobs": 25,
      "queues": {
        "webhooks-high": 5,
        "webhooks-normal": 15,
        "lgpd-normal": 3,
        "exports-bulk": 2,
        "default": 0
      },
      "failed_jobs": 0,
      "status": "ok"
    }
  },
  "queues": {
    "queues": {
      "webhooks-high": {
        "name": "webhooks-high",
        "size": 5,
        "processing": 2,
        "status": "healthy"
      },
      "webhooks-normal": {
        "name": "webhooks-normal",
        "size": 15,
        "processing": 8,
        "status": "healthy"
      },
      "lgpd-normal": {
        "name": "lgpd-normal",
        "size": 3,
        "processing": 1,
        "status": "healthy"
      },
      "exports-bulk": {
        "name": "exports-bulk",
        "size": 2,
        "processing": 0,
        "status": "healthy"
      },
      "default": {
        "name": "default",
        "size": 0,
        "processing": 0,
        "status": "healthy"
      }
    },
    "total_jobs": 25,
    "failed_jobs": 0,
    "processing_jobs": 11
  },
  "response_time_ms": 125.8
}
```

### 3. Readiness Probe
```http
GET /api/health/readiness
```

**Response**:
```json
{
  "ready": true,
  "timestamp": "2025-10-14T13:00:00Z",
  "checks": {
    "database": {
      "status": "ok",
      "message": "Database connected",
      "response_time_ms": 5.2
    },
    "redis": {
      "status": "ok",
      "message": "Redis connected",
      "response_time_ms": 2.1
    },
    "queue": {
      "status": "ok",
      "message": "Queue healthy",
      "queue_size": 25,
      "max_size": 1000
    }
  },
  "message": "Service is ready to accept traffic"
}
```

### 4. Liveness Probe
```http
GET /api/health/liveness
```

**Response**:
```json
{
  "alive": true,
  "timestamp": "2025-10-14T13:00:00Z",
  "uptime": "up 5 days, 2 hours",
  "memory_usage": {
    "bytes": 67108864,
    "percent": 26.2,
    "limit": "256M"
  }
}
```

### 5. System Metrics
```http
GET /api/health/metrics
```

**Response**:
```json
{
  "success": true,
  "timestamp": "2025-10-14T13:00:00Z",
  "metrics": {
    "memory": {
      "usage_bytes": 67108864,
      "peak_bytes": 134217728,
      "limit": "256M"
    },
    "database": {
      "active_connections": 5,
      "database_size": "125 MB",
      "status": "ok"
    },
    "redis": {
      "used_memory": "45.2M",
      "connected_clients": 3,
      "total_commands_processed": 125000,
      "keyspace_hits": 120000,
      "keyspace_misses": 5000,
      "status": "ok"
    },
    "cache": {
      "hits": 120000,
      "misses": 5000,
      "hit_rate": 96.0,
      "status": "ok"
    },
    "queue": {
      "total_jobs": 25,
      "queues": {
        "webhooks-high": 5,
        "webhooks-normal": 15,
        "lgpd-normal": 3,
        "exports-bulk": 2,
        "default": 0
      },
      "failed_jobs": 0,
      "status": "ok"
    }
  }
}
```

### 6. Queue Status
```http
GET /api/health/queues
```

**Response**:
```json
{
  "success": true,
  "timestamp": "2025-10-14T13:00:00Z",
  "queues": {
    "queues": {
      "webhooks-high": {
        "name": "webhooks-high",
        "size": 5,
        "processing": 2,
        "status": "healthy"
      },
      "webhooks-normal": {
        "name": "webhooks-normal",
        "size": 15,
        "processing": 8,
        "status": "healthy"
      },
      "lgpd-normal": {
        "name": "lgpd-normal",
        "size": 3,
        "processing": 1,
        "status": "healthy"
      },
      "exports-bulk": {
        "name": "exports-bulk",
        "size": 2,
        "processing": 0,
        "status": "healthy"
      },
      "default": {
        "name": "default",
        "size": 0,
        "processing": 0,
        "status": "healthy"
      }
    },
    "total_jobs": 25,
    "failed_jobs": 0,
    "processing_jobs": 11
  }
}
```

## Error Responses

### Health Check Failed
```json
{
  "status": "unhealthy",
  "timestamp": "2025-10-14T13:00:00Z",
  "service": "Healthcare CRM Bridge",
  "version": "2.1",
  "error": "Health check failed"
}
```

### Validation Error
```json
{
  "success": false,
  "error": "Validation failed",
  "error_code": "VALIDATION_ERROR",
  "details": {
    "timeout": ["Timeout must be at least 1 second"]
  },
  "timestamp": "2025-10-14T13:00:00Z"
}
```

### Service Unavailable
```json
{
  "success": false,
  "error": "Metrics retrieval failed",
  "error_code": "METRICS_RETRIEVAL_FAILED",
  "timestamp": "2025-10-14T13:00:00Z"
}
```

## Security Features

### Rate Limiting
- **Basic Health**: No rate limiting (public endpoint)
- **Detailed Health**: 60 requests/minute per IP
- **Metrics/Queues**: 60 requests/minute per IP

### Input Validation
- Boolean validation for `include_metrics` and `include_queues`
- Integer validation for `timeout` parameter (1-30 seconds)
- XSS prevention through input sanitization

### Error Handling
- Comprehensive exception handling
- Detailed error logging for debugging
- Standardized error response format
- Graceful degradation on service failures

## Performance Optimizations

### Caching
- System metrics cached for 1 minute
- Database queries optimized with timeouts
- Redis connection pooling
- Response time monitoring

### Timeouts
- Database queries: 5 seconds
- External API calls: 5 seconds
- Redis operations: 2 seconds
- Overall health check: 30 seconds max

### Resource Monitoring
- Memory usage tracking
- Database connection monitoring
- Queue backlog detection
- Cache hit rate analysis

## Kubernetes Integration

### Readiness Probe
```yaml
readinessProbe:
  httpGet:
    path: /api/health/readiness
    port: 80
  initialDelaySeconds: 10
  periodSeconds: 5
  timeoutSeconds: 3
  successThreshold: 1
  failureThreshold: 3
```

### Liveness Probe
```yaml
livenessProbe:
  httpGet:
    path: /api/health/liveness
    port: 80
  initialDelaySeconds: 30
  periodSeconds: 10
  timeoutSeconds: 5
  successThreshold: 1
  failureThreshold: 3
```

## Monitoring Integration

### Prometheus Metrics
The health endpoints provide data that can be scraped by Prometheus for monitoring:

- Database connection status
- Redis memory usage
- Queue sizes and processing rates
- API response times
- Memory usage and limits

### Alerting Rules
```yaml
# Example Prometheus alerting rules
groups:
  - name: bridge-health
    rules:
      - alert: BridgeServiceDown
        expr: up{job="bridge-health"} == 0
        for: 1m
        labels:
          severity: critical
        annotations:
          summary: "Bridge service is down"
      
      - alert: HighQueueBacklog
        expr: bridge_queue_size > 1000
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "High queue backlog detected"
      
      - alert: DatabaseConnectionFailed
        expr: bridge_database_status == 0
        for: 1m
        labels:
          severity: critical
        annotations:
          summary: "Database connection failed"
```

## Usage Examples

### Load Balancer Health Check
```bash
curl -f http://bridge.yourdomain.com/api/health
```

### Kubernetes Deployment
```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: bridge-service
spec:
  replicas: 3
  selector:
    matchLabels:
      app: bridge-service
  template:
    metadata:
      labels:
        app: bridge-service
    spec:
      containers:
      - name: bridge
        image: bridge:latest
        ports:
        - containerPort: 80
        livenessProbe:
          httpGet:
            path: /api/health/liveness
            port: 80
          initialDelaySeconds: 30
          periodSeconds: 10
        readinessProbe:
          httpGet:
            path: /api/health/readiness
            port: 80
          initialDelaySeconds: 10
          periodSeconds: 5
```

### Monitoring Script
```bash
#!/bin/bash
# Health monitoring script

HEALTH_URL="http://bridge.yourdomain.com/api/health"
DETAILED_URL="http://bridge.yourdomain.com/api/health/detailed"

# Basic health check
if curl -f -s "$HEALTH_URL" > /dev/null; then
    echo "✅ Basic health check passed"
else
    echo "❌ Basic health check failed"
    exit 1
fi

# Detailed health check
RESPONSE=$(curl -s "$DETAILED_URL")
STATUS=$(echo "$RESPONSE" | jq -r '.status')

if [ "$STATUS" = "healthy" ]; then
    echo "✅ Detailed health check passed"
else
    echo "❌ Detailed health check failed: $STATUS"
    echo "$RESPONSE" | jq '.'
    exit 1
fi
```

## Configuration

### Environment Variables
```env
# Health check configuration
HEALTH_CACHE_TTL=60
HEALTH_TIMEOUT=5
HEALTH_MEMORY_LIMIT=90

# External API endpoints
KRAYIN_BASE_URL=https://crm.yourdomain.com
CHATWOOT_BASE_URL=https://chat.yourdomain.com
```

### Service Configuration
```php
// config/health.php
return [
    'cache_ttl' => env('HEALTH_CACHE_TTL', 60),
    'timeout' => env('HEALTH_TIMEOUT', 5),
    'memory_limit_percent' => env('HEALTH_MEMORY_LIMIT', 90),
    'queue_warning_threshold' => 1000,
    'external_apis' => [
        'krayin' => env('KRAYIN_BASE_URL'),
        'chatwoot' => env('CHATWOOT_BASE_URL'),
    ],
];
```

## Troubleshooting

### Common Issues

1. **Database Connection Failed**
   - Check database credentials
   - Verify database server is running
   - Check network connectivity

2. **Redis Connection Failed**
   - Verify Redis server is running
   - Check Redis configuration
   - Verify network connectivity

3. **External API Timeouts**
   - Check external service status
   - Verify network connectivity
   - Check API endpoint URLs

4. **High Queue Backlog**
   - Check queue worker status
   - Verify Redis queue configuration
   - Monitor queue processing rates

### Debug Mode
```bash
# Enable debug logging
LOG_LEVEL=debug php artisan serve

# Check specific health components
curl "http://localhost/api/health/detailed?include_metrics=true&include_queues=true"
```

## Performance Considerations

- Health checks are designed to be lightweight and fast
- Caching reduces database load for repeated requests
- Timeouts prevent hanging requests
- Resource usage is monitored to prevent overload
- Queue monitoring helps identify processing bottlenecks

## Security Considerations

- Rate limiting prevents abuse
- Input validation prevents injection attacks
- Error messages don't expose sensitive information
- Logging provides audit trail for security monitoring
- Health endpoints are designed for monitoring, not data access

---

**Document Version**: 1.0  
**Last Updated**: October 14, 2025  
**Compatibility**: Bridge Service v2.1  
**Dependencies**: Laravel 10, Redis, PostgreSQL
