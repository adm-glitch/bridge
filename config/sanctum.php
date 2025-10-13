<?php

use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Healthcare CRM Bridge Service - Security Enhanced Stateful Domains
    | 
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Restricted to Krayin and Chatwoot domains only
    | for enhanced security as per API v2.1 requirements.
    |
    */

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s%s',
        'localhost,localhost:3000,localhost:8080,127.0.0.1,127.0.0.1:8000,127.0.0.1:3000,127.0.0.1:8080,::1',
        env('KRAYIN_URL') ? ',' . parse_url(env('KRAYIN_URL'), PHP_URL_HOST) : '',
        env('CHATWOOT_URL') ? ',' . parse_url(env('CHATWOOT_URL'), PHP_URL_HOST) : ''
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. For the healthcare CRM
    | bridge service, we use both web and API guards for comprehensive
    | authentication coverage.
    |
    */

    'guard' => ['web', 'api'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | SECURITY CRITICAL: Token expiration set to 60 minutes as per API v2.1
    | security requirements. This ensures tokens are short-lived and reduces
    | the risk of unauthorized access to healthcare data.
    |
    | Previous version (v1.0) had unlimited token expiration - this is a
    | breaking change for enhanced security.
    |
    */

    'expiration' => 60, // 60 minutes as per API v2.1 security requirements

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Enhanced security token prefix for healthcare CRM bridge service.
    | This helps identify tokens in logs and provides additional security
    | scanning protection.
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'hcrm_bridge_'),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | Healthcare CRM Bridge Service - Enhanced Middleware Configuration
    | 
    | Customized middleware stack for healthcare data protection and
    | LGPD compliance requirements.
    |
    */

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => App\Http\Middleware\EncryptCookies::class,
        'verify_csrf_token' => App\Http\Middleware\VerifyCsrfToken::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Configuration
    |--------------------------------------------------------------------------
    |
    | Additional security settings for healthcare CRM bridge service.
    |
    */

    'security' => [
        // Token rotation settings
        'token_rotation' => true,
        'token_rotation_interval' => 30, // minutes

        // Rate limiting for token generation
        'token_generation_limit' => 5, // per minute per user
        'token_generation_window' => 60, // seconds

        // Session security
        'session_timeout' => 3600, // 1 hour in seconds
        'concurrent_sessions_limit' => 3, // max concurrent sessions per user

        // Security headers for token responses
        'security_headers' => [
            'X-Token-Type' => 'Bearer',
            'X-Token-Expires-In' => 3600, // 60 minutes in seconds
            'X-Token-Rotation-Required' => false,
            'X-Session-Timeout' => 3600,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | LGPD Compliance Settings
    |--------------------------------------------------------------------------
    |
    | Brazilian data protection law compliance settings for token management.
    |
    */

    'lgpd' => [
        'data_retention_days' => 30, // Token data retention period
        'audit_logging' => true, // Log all token activities
        'consent_required' => true, // Require explicit consent for token generation
        'data_processing_purpose' => 'healthcare_crm_authentication',
        'privacy_policy_version' => '2.1',
    ],

    /*
    |--------------------------------------------------------------------------
    | Environment-Specific Configuration
    |--------------------------------------------------------------------------
    |
    | Different token settings for different environments.
    |
    */

    'environments' => [
        'local' => [
            'expiration' => 120, // 2 hours for development
            'token_prefix' => 'dev_hcrm_',
            'security' => [
                'token_rotation' => false,
                'concurrent_sessions_limit' => 10,
            ],
        ],

        'testing' => [
            'expiration' => 30, // 30 minutes for testing
            'token_prefix' => 'test_hcrm_',
            'security' => [
                'token_rotation' => true,
                'concurrent_sessions_limit' => 5,
            ],
        ],

        'production' => [
            'expiration' => 60, // 60 minutes for production
            'token_prefix' => 'hcrm_bridge_',
            'security' => [
                'token_rotation' => true,
                'concurrent_sessions_limit' => 3,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Abilities and Scopes
    |--------------------------------------------------------------------------
    |
    | Define token abilities for fine-grained access control in healthcare CRM.
    |
    */

    'abilities' => [
        'conversations:read' => 'Read conversation data',
        'conversations:write' => 'Create and update conversations',
        'messages:read' => 'Read message data',
        'messages:write' => 'Create and update messages',
        'insights:read' => 'Read AI insights and analytics',
        'webhooks:receive' => 'Receive webhook notifications',
        'lgpd:export' => 'Export user data (LGPD compliance)',
        'lgpd:delete' => 'Delete user data (LGPD compliance)',
        'admin:read' => 'Administrative read access',
        'admin:write' => 'Administrative write access',
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting Configuration
    |--------------------------------------------------------------------------
    |
    | Rate limiting settings for token operations to prevent abuse.
    |
    */

    'rate_limiting' => [
        'token_generation' => [
            'max_attempts' => 5,
            'decay_minutes' => 1,
        ],
        'token_refresh' => [
            'max_attempts' => 10,
            'decay_minutes' => 1,
        ],
        'token_revocation' => [
            'max_attempts' => 20,
            'decay_minutes' => 1,
        ],
    ],

];
