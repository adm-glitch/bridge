<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Krayin CRM Integration
    |--------------------------------------------------------------------------
    |
    | Configuration for Krayin CRM API integration with enhanced security
    | and performance features.
    |
    */

    'krayin' => [
        'base_url' => env('KRAYIN_URL', 'https://krayin.yourdomain.com'),
        'api_token' => env('KRAYIN_API_TOKEN'),
        'default_pipeline_id' => env('KRAYIN_DEFAULT_PIPELINE_ID', 1),
        'default_stage_id' => env('KRAYIN_DEFAULT_STAGE_ID', 1),
        'timeout' => env('KRAYIN_API_TIMEOUT', 10),
        'retry_attempts' => env('KRAYIN_API_RETRY_ATTEMPTS', 3),
        'retry_delay' => env('KRAYIN_API_RETRY_DELAY', 1000),
        'cache_ttl' => [
            'lead' => env('KRAYIN_CACHE_LEAD_TTL', 300), // 5 minutes
            'pipeline' => env('KRAYIN_CACHE_PIPELINE_TTL', 3600), // 1 hour
            'stages' => env('KRAYIN_CACHE_STAGES_TTL', 86400), // 24 hours
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

    /*
    |--------------------------------------------------------------------------
    | Chatwoot Integration
    |--------------------------------------------------------------------------
    |
    | Configuration for Chatwoot API integration with webhook security.
    |
    */

    'chatwoot' => [
        'base_url' => env('CHATWOOT_URL', 'https://chatwoot.yourdomain.com'),
        'api_token' => env('CHATWOOT_API_TOKEN'),
        'webhook_secret' => env('CHATWOOT_WEBHOOK_SECRET'),
        'timeout' => env('CHATWOOT_API_TIMEOUT', 10),
        'retry_attempts' => env('CHATWOOT_API_RETRY_ATTEMPTS', 3),
        'retry_delay' => env('CHATWOOT_API_RETRY_DELAY', 1000),
        'cache_ttl' => [
            'conversation' => env('CHATWOOT_CACHE_CONVERSATION_TTL', 300), // 5 minutes
            'messages' => env('CHATWOOT_CACHE_MESSAGES_TTL', 60), // 1 minute
            'contact' => env('CHATWOOT_CACHE_CONTACT_TTL', 600), // 10 minutes
            'account' => env('CHATWOOT_CACHE_ACCOUNT_TTL', 3600), // 1 hour
        ],
        'security' => [
            'verify_ssl' => env('CHATWOOT_VERIFY_SSL', true),
            'user_agent' => env('CHATWOOT_USER_AGENT', 'Bridge-Service/2.1'),
            'max_redirects' => env('CHATWOOT_MAX_REDIRECTS', 3),
        ],
        'webhook_security' => [
            'timestamp_enabled' => env('CHATWOOT_WEBHOOK_TIMESTAMP_ENABLED', true),
            'max_payload_size' => env('WEBHOOK_MAX_PAYLOAD_SIZE', 1048576), // 1MB
            'timestamp_tolerance' => env('WEBHOOK_TIMESTAMP_TOLERANCE', 300), // 5 minutes
            'idempotency_ttl' => env('WEBHOOK_IDEMPOTENCY_TTL', 86400), // 24 hours
        ],
        'endpoints' => [
            'conversations' => env('CHATWOOT_CONVERSATIONS_ENDPOINT', '/api/v1/conversations'),
            'messages' => env('CHATWOOT_MESSAGES_ENDPOINT', '/api/v1/messages'),
            'contacts' => env('CHATWOOT_CONTACTS_ENDPOINT', '/api/v1/contacts'),
            'accounts' => env('CHATWOOT_ACCOUNTS_ENDPOINT', '/api/v1/accounts'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | LGPD Compliance Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for LGPD (Lei Geral de Proteção de Dados) compliance
    | with consent management, data retention, and audit trails.
    |
    */

    'lgpd' => [
        'consent_version' => env('LGPD_CONSENT_VERSION', '2.1'),
        'retention_days' => env('LGPD_RETENTION_DAYS', 1825), // 5 years
        'consent_types' => [
            'data_processing' => 'Data Processing Consent',
            'marketing' => 'Marketing Communications Consent',
            'health_data' => 'Health Data Consent',
            'analytics' => 'Analytics and Performance Consent',
        ],
        'consent_validity_days' => [
            'data_processing' => env('LGPD_CONSENT_VALIDITY_DATA_PROCESSING', 365),
            'marketing' => env('LGPD_CONSENT_VALIDITY_MARKETING', 365),
            'health_data' => env('LGPD_CONSENT_VALIDITY_HEALTH_DATA', 730), // 2 years
            'analytics' => env('LGPD_CONSENT_VALIDITY_ANALYTICS', 365),
        ],
        'consent_texts' => [
            'data_processing' => env('LGPD_CONSENT_TEXT_DATA_PROCESSING', 'Autorizo o processamento dos meus dados pessoais conforme a Lei Geral de Proteção de Dados (LGPD).'),
            'marketing' => env('LGPD_CONSENT_TEXT_MARKETING', 'Autorizo o envio de comunicações de marketing.'),
            'health_data' => env('LGPD_CONSENT_TEXT_HEALTH_DATA', 'Autorizo o armazenamento e processamento de dados sensíveis de saúde para fins de atendimento médico.'),
            'analytics' => env('LGPD_CONSENT_TEXT_ANALYTICS', 'Autorizo o uso de dados para análise e melhoria dos serviços.'),
        ],
        'retention_policies' => [
            'data_processing' => env('LGPD_RETENTION_DATA_PROCESSING', 1825), // 5 years
            'marketing' => env('LGPD_RETENTION_MARKETING', 365), // 1 year
            'health_data' => env('LGPD_RETENTION_HEALTH_DATA', 2555), // 7 years
            'analytics' => env('LGPD_RETENTION_ANALYTICS', 365), // 1 year
        ],
        'cache_ttl' => [
            'consent_validity' => env('LGPD_CACHE_CONSENT_VALIDITY_TTL', 300), // 5 minutes
            'consent_active' => env('LGPD_CACHE_CONSENT_ACTIVE_TTL', 300), // 5 minutes
            'contact_consents' => env('LGPD_CACHE_CONTACT_CONSENTS_TTL', 600), // 10 minutes
            'consent_by_id' => env('LGPD_CACHE_CONSENT_BY_ID_TTL', 600), // 10 minutes
            'consent_stats' => env('LGPD_CACHE_CONSENT_STATS_TTL', 3600), // 1 hour
            'compliance_report' => env('LGPD_CACHE_COMPLIANCE_REPORT_TTL', 1800), // 30 minutes
        ],
        'common_consent_types' => env('LGPD_COMMON_CONSENT_TYPES', 'data_processing,marketing'),
        'audit_retention_days' => env('LGPD_AUDIT_RETENTION_DAYS', 2555), // 7 years
        'encryption' => [
            'enabled' => env('LGPD_ENCRYPTION_ENABLED', true),
            'algorithm' => env('LGPD_ENCRYPTION_ALGORITHM', 'AES-256-CBC'),
            'key' => env('LGPD_ENCRYPTION_KEY'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Insights Configuration
    |--------------------------------------------------------------------------
    */

    'ai_insights' => [
        'base_url' => env('AI_INSIGHTS_URL', env('APP_URL', 'http://localhost')),
        'api_token' => env('AI_INSIGHTS_API_TOKEN'),
        'timeout' => env('AI_INSIGHTS_TIMEOUT', 15),
        'retry_attempts' => env('AI_INSIGHTS_RETRY_ATTEMPTS', 3),
        'retry_delay' => env('AI_INSIGHTS_RETRY_DELAY', 1000),
        'cache_ttl' => [
            'current' => env('AI_INSIGHTS_CACHE_CURRENT_TTL', 3600),
            'historical' => env('AI_INSIGHTS_CACHE_HISTORICAL_TTL', 7200),
        ],
        'security' => [
            'verify_ssl' => env('AI_INSIGHTS_VERIFY_SSL', true),
            'user_agent' => env('AI_INSIGHTS_USER_AGENT', 'Bridge-Service/2.1'),
            'max_redirects' => env('AI_INSIGHTS_MAX_REDIRECTS', 3),
        ],
    ],

];
