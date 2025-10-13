<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Healthcare CRM Bridge Service - Security Enhanced CORS Configuration
    | 
    | This configuration implements strict CORS policies for the healthcare
    | CRM platform, restricting access to only Krayin and Chatwoot origins
    | as specified in API v2.1 security requirements.
    |
    | Security Features:
    | - Restricted origins to KRAYIN_URL and CHATWOOT_URL only
    | - Security headers for healthcare data protection
    | - Rate limiting headers
    | - LGPD compliance headers
    |
    */

    /*
    |--------------------------------------------------------------------------
    | CORS Paths
    |--------------------------------------------------------------------------
    |
    | Define the paths that should be accessible via CORS. Only API endpoints
    | and authentication paths are allowed.
    |
    */
    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'webhooks/*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Methods
    |--------------------------------------------------------------------------
    |
    | Only allow specific HTTP methods required for the API operations.
    | Restricted to prevent unnecessary attack vectors.
    |
    */
    'allowed_methods' => [
        'GET',
        'POST',
        'PUT',
        'PATCH',
        'DELETE',
        'OPTIONS',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins
    |--------------------------------------------------------------------------
    |
    | SECURITY CRITICAL: Only allow Krayin and Chatwoot origins.
    | This prevents unauthorized access from other domains.
    |
    */
    'allowed_origins' => [
        env('KRAYIN_URL'),
        env('CHATWOOT_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins Patterns
    |--------------------------------------------------------------------------
    |
    | Additional pattern-based origin validation for subdomains and ports.
    | Only allow specific patterns for Krayin and Chatwoot.
    |
    */
    'allowed_origins_patterns' => [
        // Allow Krayin subdomains (e.g., app.krayin.com, admin.krayin.com)
        preg_quote(parse_url(env('KRAYIN_URL', ''), PHP_URL_HOST) ?: '', '/') . '.*',
        // Allow Chatwoot subdomains (e.g., app.chatwoot.com, admin.chatwoot.com)
        preg_quote(parse_url(env('CHATWOOT_URL', ''), PHP_URL_HOST) ?: '', '/') . '.*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Headers
    |--------------------------------------------------------------------------
    |
    | Security-focused header allowlist. Only essential headers for API
    | functionality and security are permitted.
    |
    */
    'allowed_headers' => [
        'Accept',
        'Accept-Language',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'X-Chatwoot-Signature',
        'X-Chatwoot-Timestamp',
        'X-Request-ID',
        'X-Forwarded-For',
        'X-Real-IP',
        'User-Agent',
        'Origin',
        'Referer',
    ],

    /*
    |--------------------------------------------------------------------------
    | Exposed Headers
    |--------------------------------------------------------------------------
    |
    | Headers that the client can access in the response. Includes security
    | headers and rate limiting information as per API v2.1 specifications.
    |
    */
    'exposed_headers' => [
        // Rate limiting headers
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'X-RateLimit-Reset',
        'X-RateLimit-Reset-After',
        'Retry-After',

        // Security headers
        'X-Content-Type-Options',
        'X-Frame-Options',
        'X-XSS-Protection',
        'Strict-Transport-Security',
        'Content-Security-Policy',
        'Referrer-Policy',

        // API response headers
        'X-Request-ID',
        'X-Response-Time',
        'Cache-Control',
        'ETag',
        'Last-Modified',

        // LGPD compliance headers
        'X-Data-Processing-Consent',
        'X-Privacy-Policy-Version',
    ],

    /*
    |--------------------------------------------------------------------------
    | Max Age
    |--------------------------------------------------------------------------
    |
    | How long the browser should cache the preflight response.
    | Set to 1 hour for security while maintaining performance.
    |
    */
    'max_age' => 3600, // 1 hour

    /*
    |--------------------------------------------------------------------------
    | Supports Credentials
    |--------------------------------------------------------------------------
    |
    | Enable credentials for authenticated API requests.
    | Required for JWT token-based authentication.
    |
    */
    'supports_credentials' => true,

    /*
    |--------------------------------------------------------------------------
    | Security Headers Configuration
    |--------------------------------------------------------------------------
    |
    | Additional security headers to be applied to all CORS responses.
    | These headers enhance security for healthcare data protection.
    |
    */
    'security_headers' => [
        // Prevent MIME type sniffing
        'X-Content-Type-Options' => 'nosniff',

        // Prevent clickjacking
        'X-Frame-Options' => 'DENY',

        // XSS protection
        'X-XSS-Protection' => '1; mode=block',

        // HTTPS enforcement
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains; preload',

        // Content Security Policy for healthcare data
        'Content-Security-Policy' => 'default-src \'self\'; script-src \'self\' \'unsafe-inline\'; style-src \'self\' \'unsafe-inline\'; img-src \'self\' data: https:; connect-src \'self\'; frame-ancestors \'none\'; base-uri \'self\'; form-action \'self\';',

        // Referrer policy for privacy
        'Referrer-Policy' => 'strict-origin-when-cross-origin',

        // LGPD compliance headers
        'X-Data-Processing-Consent' => 'required',
        'X-Privacy-Policy-Version' => '2.1',

        // API versioning
        'X-API-Version' => 'v1',
        'X-Service-Name' => 'Healthcare CRM Bridge',
    ],

    /*
    |--------------------------------------------------------------------------
    | Environment-Specific Configuration
    |--------------------------------------------------------------------------
    |
    | Different CORS settings for different environments.
    |
    */
    'environments' => [
        'local' => [
            'allowed_origins' => [
                'http://localhost:3000',
                'http://localhost:8080',
                'http://127.0.0.1:3000',
                'http://127.0.0.1:8080',
                env('KRAYIN_URL'),
                env('CHATWOOT_URL'),
            ],
            'supports_credentials' => true,
        ],

        'testing' => [
            'allowed_origins' => [
                'http://localhost:3000',
                'http://localhost:8080',
                env('KRAYIN_URL'),
                env('CHATWOOT_URL'),
            ],
            'supports_credentials' => true,
        ],

        'production' => [
            'allowed_origins' => [
                env('KRAYIN_URL'),
                env('CHATWOOT_URL'),
            ],
            'supports_credentials' => true,
            'max_age' => 3600,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting Headers
    |--------------------------------------------------------------------------
    |
    | Headers to include rate limiting information in CORS responses.
    |
    */
    'rate_limit_headers' => [
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'X-RateLimit-Reset',
        'X-RateLimit-Reset-After',
        'Retry-After',
    ],

    /*
    |--------------------------------------------------------------------------
    | LGPD Compliance Headers
    |--------------------------------------------------------------------------
    |
    | Headers required for LGPD (Brazilian data protection law) compliance.
    |
    */
    'lgpd_headers' => [
        'X-Data-Processing-Consent',
        'X-Privacy-Policy-Version',
        'X-Data-Retention-Period',
        'X-Data-Processing-Purpose',
    ],

];
