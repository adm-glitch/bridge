<?php

namespace App\Jobs;

use App\Models\ConversationMapping;
use App\Models\StageChangeLog;
use App\Services\KrayinApiService;
use App\Services\AuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ProcessConversationStatusChanged implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;
    public $backoff = [60, 120, 300, 600, 1800]; // Exponential backoff
    public $timeout = 120;
    public $maxExceptions = 2;

    private string $webhookId;
    private array $payload;
    private string $ip;
    private string $userAgent;

    /**
     * Create a new job instance.
     */
    public function __construct(string $webhookId, array $payload, string $ip, string $userAgent)
    {
        $this->webhookId = $webhookId;
        $this->payload = $payload;
        $this->ip = $ip;
        $this->userAgent = $userAgent;
    }

    /**
     * Execute the job.
     */
    public function handle(
        KrayinApiService $krayinService,
        AuditService $auditService
    ): void {
        Log::info('Processing conversation_status_changed webhook', [
            'webhook_id' => $this->webhookId,
            'conversation_id' => $this->payload['id'],
            'status' => $this->payload['status'],
            'previous_status' => $this->payload['previous_status'] ?? null,
            'attempt' => $this->attempts()
        ]);

        try {
            DB::transaction(function () use ($krayinService, $auditService) {
                $conversationId = $this->payload['id'];
                $newStatus = $this->payload['status'];
                $previousStatus = $this->payload['previous_status'] ?? null;

                // Find conversation mapping
                $conversationMapping = ConversationMapping::where('chatwoot_conversation_id', $conversationId)->first();

                if (!$conversationMapping) {
                    Log::warning('Conversation mapping not found', [
                        'conversation_id' => $conversationId,
                        'webhook_id' => $this->webhookId
                    ]);

                    // Store failed webhook for manual review
                    DB::table('failed_webhooks')->insert([
                        'webhook_id' => $this->webhookId,
                        'event_type' => 'conversation_status_changed',
                        'payload' => json_encode($this->payload),
                        'error' => 'Conversation mapping not found',
                        'failed_at' => now(),
                        'attempts' => $this->attempts()
                    ]);

                    return;
                }

                // Map Chatwoot status to Krayin stage
                $stageMapping = $this->getStageMapping($newStatus);
                $previousStageMapping = $previousStatus ? $this->getStageMapping($previousStatus) : null;

                // Update lead stage in Krayin
                $leadUpdate = $krayinService->updateLeadStage(
                    $conversationMapping->krayin_lead_id,
                    $stageMapping['stage_id']
                );

                if (!$leadUpdate || !isset($leadUpdate['data'])) {
                    throw new \Exception('Failed to update lead stage in Krayin');
                }

                // Update conversation mapping
                $conversationMapping->update([
                    'status' => $newStatus,
                    'updated_at' => now()
                ]);

                // Log stage change
                $stageChangeLog = StageChangeLog::create([
                    'krayin_lead_id' => $conversationMapping->krayin_lead_id,
                    'chatwoot_conversation_id' => $conversationId,
                    'previous_stage' => $previousStageMapping['stage_name'] ?? null,
                    'new_stage' => $stageMapping['stage_name'],
                    'previous_status' => $previousStatus,
                    'new_status' => $newStatus,
                    'changed_at' => $this->payload['changed_at'] ?? now(),
                    'webhook_id' => $this->webhookId,
                    'ip_address' => $this->ip,
                    'user_agent' => $this->userAgent
                ]);

                // Log audit trail
                $auditService->logSecurityEvent('webhook_processed', [
                    'webhook_type' => 'conversation_status_changed',
                    'webhook_id' => $this->webhookId,
                    'krayin_lead_id' => $conversationMapping->krayin_lead_id,
                    'previous_stage' => $previousStageMapping['stage_name'] ?? null,
                    'new_stage' => $stageMapping['stage_name'],
                    'previous_status' => $previousStatus,
                    'new_status' => $newStatus,
                    'ip' => $this->ip,
                    'user_agent' => $this->userAgent
                ]);

                Log::info('Conversation status changed successfully', [
                    'webhook_id' => $this->webhookId,
                    'krayin_lead_id' => $conversationMapping->krayin_lead_id,
                    'previous_stage' => $previousStageMapping['stage_name'] ?? null,
                    'new_stage' => $stageMapping['stage_name'],
                    'stage_change_log_id' => $stageChangeLog->id
                ]);
            });
        } catch (\Exception $e) {
            Log::error('Failed to process conversation_status_changed webhook', [
                'webhook_id' => $this->webhookId,
                'conversation_id' => $this->payload['id'],
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
                'trace' => $e->getTraceAsString()
            ]);

            // Re-throw to trigger retry
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('Webhook processing failed permanently', [
            'webhook_id' => $this->webhookId,
            'conversation_id' => $this->payload['id'],
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
            'payload' => $this->payload
        ]);

        // Store in dead letter queue for manual review
        DB::table('failed_webhooks')->insert([
            'webhook_id' => $this->webhookId,
            'event_type' => 'conversation_status_changed',
            'payload' => json_encode($this->payload),
            'error' => $exception->getMessage(),
            'failed_at' => now(),
            'attempts' => $this->attempts()
        ]);
    }

    /**
     * Get stage mapping for Chatwoot status
     */
    private function getStageMapping(string $status): array
    {
        $mappings = [
            'open' => [
                'stage_id' => config('services.krayin.stages.in_progress', 2),
                'stage_name' => 'In Progress'
            ],
            'resolved' => [
                'stage_id' => config('services.krayin.stages.follow_up', 3),
                'stage_name' => 'Follow-up'
            ],
            'pending' => [
                'stage_id' => config('services.krayin.stages.waiting', 4),
                'stage_name' => 'Waiting'
            ],
            'snoozed' => [
                'stage_id' => config('services.krayin.stages.waiting', 4),
                'stage_name' => 'Waiting'
            ]
        ];

        return $mappings[$status] ?? $mappings['open'];
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'webhook',
            'conversation-status-changed',
            'webhook-id:' . $this->webhookId,
            'conversation-id:' . ($this->payload['id'] ?? 'unknown')
        ];
    }
}
