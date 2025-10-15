<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option controls the default authentication "guard" and password
    | reset options for your application. You may change these defaults
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | here which uses session storage and the Eloquent user provider.
    |
    | All authentication drivers have a user provider. This defines how the
    | users are actually retrieved out of your database or other storage
    | mechanisms used by this application to persist your user's data.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
        'api' => [
            'driver' => 'jwt',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication drivers have a user provider. This defines how the
    | users are actually retrieved out of your database or other storage
    | mechanisms used by this application to persist your user's data.
    |
    | If you have multiple user tables or models you may configure multiple
    | sources which represent each model / table. These sources may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | You may specify multiple password reset configurations if you have more
    | than one user table or model in the application and you want to have
    | separate password reset settings based on the specific user types.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('DB_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the amount of seconds before a password confirmation
    | window expires and the user is prompted to re-enter their password via
    | the confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

    /*
    |--------------------------------------------------------------------------
    | JWT Configuration
    |--------------------------------------------------------------------------
    |
    | JWT token configuration for API authentication.
    | Implements security requirements from API v2.1 specifications.
    |
    */

    'jwt' => [
        'secret' => env('JWT_SECRET', env('APP_KEY')),
        'algorithm' => env('JWT_ALGORITHM', 'HS256'),
        'expiration' => env('JWT_EXPIRATION', 60), // 60 minutes
        'refresh_threshold' => env('JWT_REFRESH_THRESHOLD', 5), // Refresh when 5 minutes left
        'issuer' => env('JWT_ISSUER', env('APP_URL')),
        'audience' => env('JWT_AUDIENCE', env('APP_NAME', 'Healthcare CRM Bridge')),
        'leeway' => env('JWT_LEEWAY', 0), // Clock skew tolerance in seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Configuration
    |--------------------------------------------------------------------------
    |
    | Security settings for authentication and authorization.
    |
    */

    'security' => [
        'max_login_attempts' => env('AUTH_MAX_LOGIN_ATTEMPTS', 5),
        'lockout_duration' => env('AUTH_LOCKOUT_DURATION', 15), // minutes
        'password_min_length' => env('AUTH_PASSWORD_MIN_LENGTH', 8),
        'password_require_special' => env('AUTH_PASSWORD_REQUIRE_SPECIAL', true),
        'session_timeout' => env('AUTH_SESSION_TIMEOUT', 120), // minutes
        'remember_me_duration' => env('AUTH_REMEMBER_ME_DURATION', 30), // days
    ],

    /*
    |--------------------------------------------------------------------------
    | LGPD Compliance Configuration
    |--------------------------------------------------------------------------
    |
    | Brazilian data protection law compliance settings.
    |
    */

    'lgpd' => [
        'consent_required' => env('LGPD_CONSENT_REQUIRED', true),
        'data_retention_days' => env('LGPD_DATA_RETENTION_DAYS', 1825), // 5 years
        'audit_log_retention_days' => env('LGPD_AUDIT_RETENTION_DAYS', 2555), // 7 years
        'consent_version' => env('LGPD_CONSENT_VERSION', '2.1'),
        'privacy_policy_url' => env('LGPD_PRIVACY_POLICY_URL'),
        'data_protection_officer_email' => env('LGPD_DPO_EMAIL'),
    ],

];
