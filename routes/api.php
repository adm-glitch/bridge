<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatwootWebhookController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\AiInsightsController;
use App\Http\Controllers\Api\ConsentController;
use App\Http\Controllers\Api\DataExportController;
use App\Http\Controllers\Api\HealthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - Healthcare CRM Bridge Service v2.1 (SECURITY ENHANCED)
|--------------------------------------------------------------------------
|
| Complete API Routes with Comprehensive Security, Rate Limiting, and LGPD Compliance
| for Healthcare CRM Integration between Krayin and Chatwoot.
|
| Security Features:
| - JWT token-based authentication (60-minute expiration)
| - Webhook signature verification with replay attack prevention
| - Comprehensive rate limiting per endpoint type and user/IP
| - LGPD compliance with consent management and data export
| - Audit logging for all sensitive operations
| - CORS protection and CSRF prevention
|
| Rate Limiting Strategy:
| - Webhooks: 100 req/min, 1000 req/hour per IP
| - Data APIs: 60 req/min, 600 req/hour per user
| - AI Insights: 30 req/min, 300 req/hour per user
| - LGPD Exports: 5 req/min, 20 req/hour per user
| - Authentication: 5 req/min, 20 req/hour per IP
|
*/

// API Version 1 Routes
Route::prefix('v1')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Authentication Routes (Public with Rate Limiting)
    |--------------------------------------------------------------------------
    |
    | Rate Limits:
    | - Login: 5 req/min, 20 req/hour per IP
    | - Refresh: 5 req/min, 20 req/hour per IP
    | - Validate: 60 req/min, 600 req/hour per IP
    |
    */
    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware(['throttle.auth:login'])
            ->name('auth.login');

        Route::post('/refresh', [AuthController::class, 'refresh'])
            ->middleware(['throttle.auth:refresh'])
            ->name('auth.refresh');

        Route::post('/logout', [AuthController::class, 'logout'])
            ->middleware(['jwt.auth'])
            ->name('auth.logout');

        Route::get('/me', [AuthController::class, 'me'])
            ->middleware(['jwt.auth'])
            ->name('auth.me');

        Route::get('/validate', [AuthController::class, 'validateToken'])
            ->middleware(['throttle.auth:api'])
            ->name('auth.validate');
    });

    /*
    |--------------------------------------------------------------------------
    | Protected API Routes (JWT Authentication Required)
    |--------------------------------------------------------------------------
    |
    | All routes require JWT authentication and appropriate abilities.
    | Rate limiting applied per user for authenticated requests.
    |
    */
    Route::middleware(['jwt.auth', 'throttle.auth:api'])->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Chatwoot Integration Routes
        |--------------------------------------------------------------------------
        |
        | Rate Limits: 60 req/min, 600 req/hour per user
        | Abilities Required: conversations:read
        |
        */
        Route::prefix('chatwoot')->group(function () {
            Route::get('/conversations/{lead_id}', [ConversationController::class, 'listConversations'])
                ->middleware(['abilities:conversations:read'])
                ->name('chatwoot.conversations.list');

            Route::get('/messages/{conversation_id}', [ConversationController::class, 'listMessages'])
                ->middleware(['abilities:conversations:read'])
                ->name('chatwoot.messages.list');
        });

        /*
        |--------------------------------------------------------------------------
        | AI Insights Routes
        |--------------------------------------------------------------------------
        |
        | Rate Limits: 30 req/min, 300 req/hour per user (lower due to computation cost)
        | Abilities Required: insights:read
        |
        */
        Route::prefix('ai')->group(function () {
            Route::get('/insights/{lead_id}', [AiInsightsController::class, 'show'])
                ->middleware(['throttle.auth:ai', 'abilities:insights:read'])
                ->name('ai.insights.show');
        });

        /*
        |--------------------------------------------------------------------------
        | LGPD Compliance Routes
        |--------------------------------------------------------------------------
        |
        | Rate Limits: 5 req/min, 20 req/hour per user (LGPD exports are expensive)
        | Abilities Required: lgpd:read, lgpd:write, admin:write
        |
        */
        Route::prefix('lgpd')->group(function () {
            Route::post('/consent', [ConsentController::class, 'recordConsent'])
                ->middleware(['throttle.auth:lgpd', 'abilities:lgpd:write'])
                ->name('lgpd.consent.record');

            Route::get('/consent/{contact_id}', [ConsentController::class, 'getConsentStatus'])
                ->middleware(['throttle.auth:lgpd', 'abilities:lgpd:read'])
                ->name('lgpd.consent.status');

            Route::delete('/data/{contact_id}', [ConsentController::class, 'deleteData'])
                ->middleware(['throttle.auth:lgpd', 'abilities:admin:write'])
                ->name('lgpd.data.delete');

            Route::get('/export/{contact_id}', [ConsentController::class, 'exportData'])
                ->middleware(['throttle.auth:lgpd', 'abilities:lgpd:read'])
                ->name('lgpd.data.export');
        });

        /*
        |--------------------------------------------------------------------------
        | Data Export Routes (Admin Only)
        |--------------------------------------------------------------------------
        |
        | Rate Limits: 5 req/min, 20 req/hour per user
        | Abilities Required: admin:read, admin:write
        |
        */
        Route::prefix('export')->group(function () {
            Route::post('/bulk', [DataExportController::class, 'bulkExport'])
                ->middleware(['throttle.auth:export', 'abilities:admin:write'])
                ->name('export.bulk');

            Route::get('/status/{export_id}', [DataExportController::class, 'getExportStatus'])
                ->middleware(['throttle.auth:export', 'abilities:admin:read'])
                ->name('export.status');

            Route::get('/download/{filename}', [DataExportController::class, 'downloadExport'])
                ->middleware(['throttle.auth:export', 'abilities:admin:read'])
                ->name('export.download');

            Route::get('/history', [DataExportController::class, 'getExportHistory'])
                ->middleware(['throttle.auth:export', 'abilities:admin:read'])
                ->name('export.history');

            Route::delete('/{export_id}', [DataExportController::class, 'cancelExport'])
                ->middleware(['throttle.auth:export', 'abilities:admin:write'])
                ->name('export.cancel');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Webhook Routes (Public with Signature Verification)
    |--------------------------------------------------------------------------
    |
    | Rate Limits: 100 req/min, 1000 req/hour per IP
    | Security: Chatwoot signature verification with timestamp validation
    | Features: Replay attack prevention, payload size limits, idempotency
    |
    */
    Route::prefix('webhooks/chatwoot')->group(function () {
        Route::post('/conversation-created', [ChatwootWebhookController::class, 'conversationCreated'])
            ->middleware(['throttle.auth:webhook', 'verify.chatwoot.signature'])
            ->name('webhooks.chatwoot.conversation.created');

        Route::post('/message-created', [ChatwootWebhookController::class, 'messageCreated'])
            ->middleware(['throttle.auth:webhook', 'verify.chatwoot.signature'])
            ->name('webhooks.chatwoot.message.created');

        Route::post('/conversation-status-changed', [ChatwootWebhookController::class, 'statusChanged'])
            ->middleware(['throttle.auth:webhook', 'verify.chatwoot.signature'])
            ->name('webhooks.chatwoot.conversation.status.changed');

        // Webhook testing and monitoring endpoints
        Route::get('/test', [ChatwootWebhookController::class, 'test'])
            ->middleware(['throttle.auth:webhook'])
            ->name('webhooks.chatwoot.test');

        Route::get('/status', [ChatwootWebhookController::class, 'status'])
            ->middleware(['throttle.auth:webhook'])
            ->name('webhooks.chatwoot.status');
    });
});

/*
|--------------------------------------------------------------------------
| Health Check Routes (Public with Basic Rate Limiting)
|--------------------------------------------------------------------------
|
| Rate Limits: 60 req/min, 600 req/hour per IP
| Purpose: System monitoring, load balancer health checks, uptime monitoring
|
*/
Route::get('/health', [HealthController::class, 'index'])
    ->name('health.index');

Route::middleware(['throttle.auth:api'])
    ->prefix('health')
    ->group(function () {
        Route::get('/detailed', [HealthController::class, 'detailed'])
            ->name('health.detailed');

        Route::get('/readiness', [HealthController::class, 'readiness'])
            ->name('health.readiness');

        Route::get('/liveness', [HealthController::class, 'liveness'])
            ->name('health.liveness');

        Route::get('/metrics', [HealthController::class, 'metrics'])
            ->name('health.metrics');

        Route::get('/queues', [HealthController::class, 'queues'])
            ->name('health.queues');
    });

/*
|--------------------------------------------------------------------------
| Legacy Routes (Backward Compatibility)
|--------------------------------------------------------------------------
|
| Maintains compatibility with existing integrations while encouraging
| migration to v1 API endpoints.
|
*/
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
