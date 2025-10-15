<?php

namespace App\Services;

use App\Models\ContactMapping;
use App\Models\ConversationMapping;
use App\Models\ActivityMapping;
use App\Models\StageChangeLog;
use App\Jobs\ProcessConversationCreated;
use App\Jobs\ProcessMessageCreated;
use App\Jobs\ProcessConversationStatusChanged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class WebhookService
{
    /**
     * Process conversation created webhook
     */
    public function processConversationCreated(array $payload): array
    {
        try {
            $contact = $payload['contact'];
            $conversationId = $payload['id'];

            // Check if mapping already exists
            $existingMapping = ContactMapping::where('chatwoot_contact_id', $contact['id'])->first();

            if ($existingMapping) {
                // Create conversation mapping if it doesn't exist
                $conversationMapping = ConversationMapping::where('chatwoot_conversation_id', $conversationId)->first();

                if (!$conversationMapping) {
                    $conversationMapping = ConversationMapping::create([
                        'chatwoot_conversation_id' => $conversationId,
                        'krayin_lead_id' => $existingMapping->krayin_lead_id,
                        'status' => $payload['status'],
                        'created_at' => $payload['created_at']
                    ]);
                }

                return [
                    'success' => true,
                    'krayin_lead_id' => $existingMapping->krayin_lead_id,
                    'contact_mapping_id' => $existingMapping->id,
                    'conversation_mapping_id' => $conversationMapping->id,
                    'action' => 'conversation_mapping_created'
                ];
            }

            return [
                'success' => false,
                'error' => 'Contact mapping not found',
                'action' => 'requires_lead_creation'
            ];
        } catch (\Exception $e) {
            Log::error('Failed to process conversation created', [
                'payload' => $payload,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Process message created webhook
     */
    public function processMessageCreated(array $payload): array
    {
        try {
            $conversationId = $payload['conversation_id'];
            $messageId = $payload['id'];

            // Find conversation mapping
            $conversationMapping = ConversationMapping::where('chatwoot_conversation_id', $conversationId)->first();

            if (!$conversationMapping) {
                return [
                    'success' => false,
                    'error' => 'Conversation mapping not found',
                    'action' => 'requires_conversation_mapping'
                ];
            }

            // Check if activity mapping already exists
            $existingActivity = ActivityMapping::where('chatwoot_message_id', $messageId)->first();

            if ($existingActivity) {
                return [
                    'success' => true,
                    'krayin_activity_id' => $existingActivity->krayin_activity_id,
                    'activity_mapping_id' => $existingActivity->id,
                    'action' => 'already_processed'
                ];
            }

            return [
                'success' => false,
                'error' => 'Activity mapping not found',
                'action' => 'requires_activity_creation'
            ];
        } catch (\Exception $e) {
            Log::error('Failed to process message created', [
                'payload' => $payload,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Process conversation status changed webhook
     */
    public function processConversationStatusChanged(array $payload): array
    {
        try {
            $conversationId = $payload['id'];
            $newStatus = $payload['status'];
            $previousStatus = $payload['previous_status'] ?? null;

            // Find conversation mapping
            $conversationMapping = ConversationMapping::where('chatwoot_conversation_id', $conversationId)->first();

            if (!$conversationMapping) {
                return [
                    'success' => false,
                    'error' => 'Conversation mapping not found',
                    'action' => 'requires_conversation_mapping'
                ];
            }

            // Check if status change was already processed
            $existingLog = StageChangeLog::where('webhook_id', $payload['webhook_id'] ?? null)
                ->where('chatwoot_conversation_id', $conversationId)
                ->first();

            if ($existingLog) {
                return [
                    'success' => true,
                    'krayin_lead_id' => $conversationMapping->krayin_lead_id,
                    'stage_change_log_id' => $existingLog->id,
                    'action' => 'already_processed'
                ];
            }

            return [
                'success' => false,
                'error' => 'Stage change not found',
                'action' => 'requires_stage_change'
            ];
        } catch (\Exception $e) {
            Log::error('Failed to process conversation status changed', [
                'payload' => $payload,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get webhook processing status
     */
    public function getWebhookStatus(string $webhookId): array
    {
        try {
            $cacheKey = "webhook_processed:{$webhookId}";
            $isProcessed = Cache::has($cacheKey);

            if ($isProcessed) {
                return [
                    'success' => true,
                    'webhook_id' => $webhookId,
                    'status' => 'completed',
                    'processed_at' => Cache::get($cacheKey . ':timestamp'),
                    'message' => 'Webhook has been processed successfully'
                ];
            }

            // Check if it's in the failed webhooks table
            $failedWebhook = DB::table('failed_webhooks')
                ->where('webhook_id', $webhookId)
                ->first();

            if ($failedWebhook) {
                return [
                    'success' => false,
                    'webhook_id' => $webhookId,
                    'status' => 'failed',
                    'error' => $failedWebhook->error,
                    'failed_at' => $failedWebhook->failed_at,
                    'attempts' => $failedWebhook->attempts,
                    'message' => 'Webhook processing failed'
                ];
            }

            return [
                'success' => true,
                'webhook_id' => $webhookId,
                'status' => 'pending',
                'message' => 'Webhook is pending processing'
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get webhook status', [
                'webhook_id' => $webhookId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Retry failed webhook
     */
    public function retryFailedWebhook(string $webhookId): array
    {
        try {
            $failedWebhook = DB::table('failed_webhooks')
                ->where('webhook_id', $webhookId)
                ->first();

            if (!$failedWebhook) {
                return [
                    'success' => false,
                    'error' => 'Failed webhook not found'
                ];
            }

            $payload = json_decode($failedWebhook->payload, true);
            $eventType = $failedWebhook->event_type;

            // Dispatch appropriate job based on event type
            switch ($eventType) {
                case 'conversation_created':
                    ProcessConversationCreated::dispatch($webhookId, $payload, request()->ip(), request()->userAgent())
                        ->onQueue('webhooks-high');
                    break;

                case 'message_created':
                    ProcessMessageCreated::dispatch($webhookId, $payload, request()->ip(), request()->userAgent())
                        ->onQueue('webhooks-normal');
                    break;

                case 'conversation_status_changed':
                    ProcessConversationStatusChanged::dispatch($webhookId, $payload, request()->ip(), request()->userAgent())
                        ->onQueue('webhooks-high');
                    break;

                default:
                    return [
                        'success' => false,
                        'error' => 'Unknown event type'
                    ];
            }

            // Remove from failed webhooks table
            DB::table('failed_webhooks')->where('webhook_id', $webhookId)->delete();

            return [
                'success' => true,
                'webhook_id' => $webhookId,
                'message' => 'Webhook retry dispatched',
                'event_type' => $eventType
            ];
        } catch (\Exception $e) {
            Log::error('Failed to retry webhook', [
                'webhook_id' => $webhookId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get webhook statistics
     */
    public function getWebhookStatistics(int $days = 7): array
    {
        try {
            $startDate = now()->subDays($days);

            $stats = [
                'total_webhooks' => 0,
                'successful_webhooks' => 0,
                'failed_webhooks' => 0,
                'pending_webhooks' => 0,
                'by_event_type' => [],
                'by_status' => [],
                'average_processing_time' => 0
            ];

            // Get webhook counts by event type
            $eventTypes = ['conversation_created', 'message_created', 'conversation_status_changed'];

            foreach ($eventTypes as $eventType) {
                $count = DB::table('audit_logs')
                    ->where('action', 'webhook_processed')
                    ->where('changes->webhook_type', $eventType)
                    ->where('created_at', '>=', $startDate)
                    ->count();

                $stats['by_event_type'][$eventType] = $count;
                $stats['total_webhooks'] += $count;
            }

            // Get failed webhooks count
            $stats['failed_webhooks'] = DB::table('failed_webhooks')
                ->where('failed_at', '>=', $startDate)
                ->count();

            // Calculate success rate
            if ($stats['total_webhooks'] > 0) {
                $stats['success_rate'] = round(($stats['total_webhooks'] / ($stats['total_webhooks'] + $stats['failed_webhooks'])) * 100, 2);
            } else {
                $stats['success_rate'] = 0;
            }

            return [
                'success' => true,
                'statistics' => $stats,
                'period_days' => $days,
                'generated_at' => now()->toIso8601String()
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get webhook statistics', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
