<?php

namespace App\Jobs;

use App\Models\ConversationMapping;
use App\Models\ActivityMapping;
use App\Services\KrayinApiService;
use App\Services\AuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ProcessMessageCreated implements ShouldQueue
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
        Log::info('Processing message_created webhook', [
            'webhook_id' => $this->webhookId,
            'message_id' => $this->payload['id'],
            'conversation_id' => $this->payload['conversation_id'],
            'attempt' => $this->attempts()
        ]);

        try {
            DB::transaction(function () use ($krayinService, $auditService) {
                $conversationId = $this->payload['conversation_id'];
                $messageId = $this->payload['id'];

                // Find conversation mapping
                $conversationMapping = ConversationMapping::where('chatwoot_conversation_id', $conversationId)->first();

                if (!$conversationMapping) {
                    Log::warning('Conversation mapping not found', [
                        'conversation_id' => $conversationId,
                        'webhook_id' => $this->webhookId,
                        'message_id' => $messageId
                    ]);

                    // Store failed webhook for manual review
                    DB::table('failed_webhooks')->insert([
                        'webhook_id' => $this->webhookId,
                        'event_type' => 'message_created',
                        'payload' => json_encode($this->payload),
                        'error' => 'Conversation mapping not found',
                        'failed_at' => now(),
                        'attempts' => $this->attempts()
                    ]);

                    return;
                }

                // Create activity in Krayin
                $activityData = $this->buildActivityData();
                $activity = $krayinService->createActivity($conversationMapping->krayin_lead_id, $activityData);

                if (!$activity || !isset($activity['data']['id'])) {
                    throw new \Exception('Failed to create activity in Krayin');
                }

                $krayinActivityId = $activity['data']['id'];

                // Store activity mapping
                $activityMapping = ActivityMapping::create([
                    'chatwoot_message_id' => $messageId,
                    'krayin_activity_id' => $krayinActivityId,
                    'krayin_lead_id' => $conversationMapping->krayin_lead_id,
                    'message_type' => $this->payload['message_type'],
                    'content_type' => $this->payload['content_type'],
                    'content' => $this->payload['content'],
                    'sender_name' => $this->payload['sender']['name'],
                    'sender_type' => $this->payload['sender']['type'],
                    'created_at' => $this->payload['created_at']
                ]);

                // Update conversation last activity
                $conversationMapping->update([
                    'last_activity_at' => $this->payload['created_at'],
                    'message_count' => $conversationMapping->message_count + 1
                ]);

                // Log audit trail
                $auditService->logSecurityEvent('webhook_processed', [
                    'webhook_type' => 'message_created',
                    'webhook_id' => $this->webhookId,
                    'krayin_lead_id' => $conversationMapping->krayin_lead_id,
                    'krayin_activity_id' => $krayinActivityId,
                    'chatwoot_message_id' => $messageId,
                    'ip' => $this->ip,
                    'user_agent' => $this->userAgent
                ]);

                Log::info('Message created successfully', [
                    'webhook_id' => $this->webhookId,
                    'krayin_lead_id' => $conversationMapping->krayin_lead_id,
                    'krayin_activity_id' => $krayinActivityId,
                    'chatwoot_message_id' => $messageId,
                    'activity_mapping_id' => $activityMapping->id
                ]);
            });
        } catch (\Exception $e) {
            Log::error('Failed to process message_created webhook', [
                'webhook_id' => $this->webhookId,
                'message_id' => $this->payload['id'],
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
            'message_id' => $this->payload['id'],
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
            'payload' => $this->payload
        ]);

        // Store in dead letter queue for manual review
        DB::table('failed_webhooks')->insert([
            'webhook_id' => $this->webhookId,
            'event_type' => 'message_created',
            'payload' => json_encode($this->payload),
            'error' => $exception->getMessage(),
            'failed_at' => now(),
            'attempts' => $this->attempts()
        ]);
    }

    /**
     * Build activity data for Krayin API
     */
    private function buildActivityData(): array
    {
        $sender = $this->payload['sender'];
        $content = $this->payload['content'];
        $messageType = $this->payload['message_type'];
        $contentType = $this->payload['content_type'];

        // Build activity title based on message type and content
        $title = $this->buildActivityTitle($sender, $messageType, $contentType);

        // Build activity description
        $description = $this->buildActivityDescription($sender, $content, $messageType, $contentType);

        return [
            'title' => $title,
            'description' => $description,
            'activity_type' => $this->getActivityType($messageType, $contentType),
            'date' => $this->payload['created_at'],
            'custom_fields' => [
                'chatwoot_message_id' => $this->payload['id'],
                'chatwoot_conversation_id' => $this->payload['conversation_id'],
                'sender_name' => $sender['name'],
                'sender_type' => $sender['type'],
                'message_type' => $messageType,
                'content_type' => $contentType,
                'is_private' => $this->payload['private'] ?? false
            ]
        ];
    }

    /**
     * Build activity title
     */
    private function buildActivityTitle(array $sender, string $messageType, string $contentType): string
    {
        $senderName = $sender['name'];
        $senderType = $sender['type'];

        if ($messageType === 'incoming') {
            return "Mensagem recebida de {$senderName}";
        } elseif ($messageType === 'outgoing') {
            return "Mensagem enviada para {$senderName}";
        } else {
            return "Atividade: {$senderName}";
        }
    }

    /**
     * Build activity description
     */
    private function buildActivityDescription(array $sender, string $content, string $messageType, string $contentType): string
    {
        $description = "Tipo: {$messageType}\n";
        $description .= "Remetente: {$sender['name']} ({$sender['type']})\n";
        $description .= "Conteúdo: {$contentType}\n\n";

        if ($contentType === 'text') {
            $description .= "Mensagem: {$content}";
        } else {
            $description .= "Arquivo anexado";
        }

        return $description;
    }

    /**
     * Get activity type for Krayin
     */
    private function getActivityType(string $messageType, string $contentType): string
    {
        if ($messageType === 'incoming') {
            return 'call'; // Use 'call' for incoming messages
        } elseif ($messageType === 'outgoing') {
            return 'email'; // Use 'email' for outgoing messages
        } else {
            return 'note'; // Use 'note' for other activities
        }
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'webhook',
            'message-created',
            'webhook-id:' . $this->webhookId,
            'message-id:' . ($this->payload['id'] ?? 'unknown')
        ];
    }
}
