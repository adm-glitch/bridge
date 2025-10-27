<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for all database work. Of course
    | you may use many connections at once using the Database library.
    |
    */

    'default' => env('DB_CONNECTION', 'pgsql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Here are each of the database connections setup for your application.
    | Of course, examples of configuring each database platform that is
    | supported by Laravel is shown below to make development simple.
    |
    |
    | All database work in Laravel is done through the PHP PDO facilities
    | so make sure you have the driver for your particular database of
    | choice installed on your machine before you begin development.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DATABASE_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DATABASE_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        /*
        |--------------------------------------------------------------------------
        | PostgreSQL - Primary Write Connection (PgBouncer Pooled)
        |--------------------------------------------------------------------------
        |
        | Healthcare CRM Bridge Service - Write Operations
        | 
        | This connection handles all write operations (INSERT, UPDATE, DELETE)
        | and is routed through PgBouncer for connection pooling and
        | performance optimization.
        |
        */
        'pgsql_write' => [
            'driver' => 'pgsql',
            'url' => env('DATABASE_WRITE_URL'),
            'host' => env('DB_WRITE_HOST', 'pgbouncer'),
            'port' => env('DB_WRITE_PORT', '6432'),
            'database' => env('DB_DATABASE', 'bridge'),
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),

            // PgBouncer specific options
            'options' => [
                PDO::ATTR_PERSISTENT => false, // PgBouncer handles persistence
                PDO::ATTR_TIMEOUT => 30,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ],

            // Connection pooling settings
            'pool' => [
                'min_connections' => env('DB_POOL_MIN', 5),
                'max_connections' => env('DB_POOL_MAX', 25),
                'idle_timeout' => env('DB_POOL_IDLE_TIMEOUT', 600),
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | PostgreSQL - Read Replica Connection
        |--------------------------------------------------------------------------
        |
        | Healthcare CRM Bridge Service - Read Operations
        | 
        | This connection handles all read operations (SELECT) and is routed
        | to read replicas for improved performance and load distribution.
        |
        */
        'pgsql_read' => [
            'driver' => 'pgsql',
            'url' => env('DATABASE_READ_URL'),
            'host' => env('DB_READ_HOST', 'postgres-replica'),
            'port' => env('DB_READ_PORT', '5432'),
            'database' => env('DB_DATABASE', 'bridge'),
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),

            // Read replica specific options
            'options' => [
                PDO::ATTR_TIMEOUT => 15, // Shorter timeout for reads
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | PostgreSQL - Default Connection (Auto-routing)
        |--------------------------------------------------------------------------
        |
        | This is the default connection that automatically routes to the
        | appropriate read/write connection based on the operation type.
        |
        */
        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DATABASE_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'bridge'),
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        /*
        |--------------------------------------------------------------------------
        | PostgreSQL - Direct Primary Connection (Bypass PgBouncer)
        |--------------------------------------------------------------------------
        |
        | Direct connection to PostgreSQL primary for administrative operations
        | and migrations. This bypasses PgBouncer for operations that require
        | direct database access.
        |
        */
        'pgsql_direct' => [
            'driver' => 'pgsql',
            'url' => env('DATABASE_DIRECT_URL'),
            'host' => env('DB_DIRECT_HOST', 'postgres-primary'),
            'port' => env('DB_DIRECT_PORT', '5432'),
            'database' => env('DB_DATABASE', 'bridge'),
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),

            // Direct connection options
            'options' => [
                PDO::ATTR_PERSISTENT => true,
                PDO::ATTR_TIMEOUT => 60,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ],
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DATABASE_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run in the database.
    |
    */

    'migrations' => 'migrations',

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as APC or Memcached. Laravel makes it easy to dig right in.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Redis Configuration (High Availability with Sentinel)
    |--------------------------------------------------------------------------
    |
    | Healthcare CRM Bridge Service - Redis Sentinel Configuration
    | 
    | Configured for high availability with Redis Sentinel for automatic
    | failover and load balancing across multiple Redis instances.
    |
    */
    'redis' => [

        'client' => env('REDIS_CLIENT', 'predis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_') . '_database_'),

            // Sentinel configuration for high availability
            'replication' => env('REDIS_REPLICATION', 'sentinel'),
            'service' => env('REDIS_SENTINEL_SERVICE', 'mymaster'),
            'parameters' => [
                'password' => env('REDIS_PASSWORD'),
                'database' => 0,
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Redis Default Connection (Sentinel)
        |--------------------------------------------------------------------------
        |
        | Primary Redis connection using Sentinel for high availability.
        | Automatically routes to master or replica based on availability.
        |
        */
        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        /*
        |--------------------------------------------------------------------------
        | Redis Cache Connection (Sentinel)
        |--------------------------------------------------------------------------
        |
        | Dedicated Redis connection for caching with Sentinel support.
        |
        */
        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],

        /*
        |--------------------------------------------------------------------------
        | Redis Queue Connection (Sentinel)
        |--------------------------------------------------------------------------
        |
        | Dedicated Redis connection for queue processing with Sentinel support.
        |
        */
        'queue' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_QUEUE_DB', '2'),
        ],

        /*
        |--------------------------------------------------------------------------
        | Redis Session Connection (Sentinel)
        |--------------------------------------------------------------------------
        |
        | Dedicated Redis connection for session storage with Sentinel support.
        |
        */
        'session' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_SESSION_DB', '3'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Database Health Monitoring
    |--------------------------------------------------------------------------
    |
    | Configuration for database health monitoring and connection testing.
    |
    */
    'health_monitoring' => [
        'enabled' => env('DB_HEALTH_MONITORING', true),
        'check_interval' => env('DB_HEALTH_CHECK_INTERVAL', 30), // seconds
        'timeout' => env('DB_HEALTH_TIMEOUT', 5), // seconds
        'retry_attempts' => env('DB_HEALTH_RETRY_ATTEMPTS', 3),

        'connections_to_monitor' => [
            'pgsql_write',
            'pgsql_read',
            'pgsql_direct',
        ],

        'alerts' => [
            'enabled' => env('DB_ALERTS_ENABLED', true),
            'webhook_url' => env('DB_ALERT_WEBHOOK_URL'),
            'email' => env('DB_ALERT_EMAIL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Environment-Specific Database Configuration
    |--------------------------------------------------------------------------
    |
    | Different database settings for different environments.
    |
    */
    'environments' => [
        'local' => [
            'default_connection' => 'pgsql',
            'read_write_split' => false,
            'connection_pooling' => false,
            'replica_reads' => false,
        ],

        'testing' => [
            'default_connection' => 'pgsql',
            'read_write_split' => false,
            'connection_pooling' => false,
            'replica_reads' => false,
        ],

        'production' => [
            'default_connection' => 'pgsql',
            'read_write_split' => true,
            'connection_pooling' => true,
            'replica_reads' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Performance Settings
    |--------------------------------------------------------------------------
    |
    | Performance optimization settings for database operations.
    |
    */
    'performance' => [
        'query_logging' => env('DB_QUERY_LOGGING', false),
        'slow_query_threshold' => env('DB_SLOW_QUERY_THRESHOLD', 1000), // milliseconds
        'connection_timeout' => env('DB_CONNECTION_TIMEOUT', 30), // seconds
        'statement_timeout' => env('DB_STATEMENT_TIMEOUT', 300), // seconds

        'optimizations' => [
            'prepared_statements' => true,
            'connection_reuse' => true,
            'query_caching' => env('DB_QUERY_CACHING', true),
            'result_caching' => env('DB_RESULT_CACHING', true),
        ],
    ],

];
