<?php

namespace App\Jobs;

use App\Models\ContactMapping;
use App\Models\ConversationMapping;
use App\Services\KrayinApiService;
use App\Services\ContactMappingService;
use App\Services\AuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ProcessConversationCreated implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public $tries = 5;
    public $backoff = [60, 120, 300, 600, 1800]; // Exponential backoff: 1min, 2min, 5min, 10min, 30min
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
        ContactMappingService $mappingService,
        AuditService $auditService
    ): void {
        Log::info('Processing conversation_created webhook', [
            'webhook_id' => $this->webhookId,
            'conversation_id' => $this->payload['id'],
            'contact_id' => $this->payload['contact']['id'] ?? null,
            'attempt' => $this->attempts()
        ]);

        try {
            DB::transaction(function () use ($krayinService, $mappingService, $auditService) {
                $contact = $this->payload['contact'];
                $conversationId = $this->payload['id'];

                // Check if mapping already exists (idempotency)
                $existingMapping = ContactMapping::where('chatwoot_contact_id', $contact['id'])->first();

                if ($existingMapping) {
                    Log::info('Contact mapping already exists', [
                        'chatwoot_contact_id' => $contact['id'],
                        'krayin_lead_id' => $existingMapping->krayin_lead_id,
                        'webhook_id' => $this->webhookId
                    ]);

                    // Create conversation mapping if it doesn't exist
                    $conversationMapping = ConversationMapping::where('chatwoot_conversation_id', $conversationId)->first();

                    if (!$conversationMapping) {
                        ConversationMapping::create([
                            'chatwoot_conversation_id' => $conversationId,
                            'krayin_lead_id' => $existingMapping->krayin_lead_id,
                            'status' => $this->payload['status'],
                            'created_at' => $this->payload['created_at']
                        ]);

                        Log::info('Conversation mapping created for existing contact', [
                            'conversation_id' => $conversationId,
                            'krayin_lead_id' => $existingMapping->krayin_lead_id
                        ]);
                    }

                    return;
                }

                // Create new lead in Krayin
                $leadData = $this->buildLeadData($contact);
                $lead = $krayinService->createLead($leadData);

                if (!$lead || !isset($lead['data']['id'])) {
                    throw new \Exception('Failed to create lead in Krayin');
                }

                $krayinLeadId = $lead['data']['id'];

                // Store contact mapping
                $contactMapping = ContactMapping::create([
                    'chatwoot_contact_id' => $contact['id'],
                    'krayin_lead_id' => $krayinLeadId,
                    'contact_name' => $contact['name'],
                    'contact_email' => $contact['email'] ?? null,
                    'contact_phone' => $contact['phone_number'] ?? null,
                    'created_at' => now()
                ]);

                // Create conversation mapping
                $conversationMapping = ConversationMapping::create([
                    'chatwoot_conversation_id' => $conversationId,
                    'krayin_lead_id' => $krayinLeadId,
                    'status' => $this->payload['status'],
                    'created_at' => $this->payload['created_at']
                ]);

                // Log audit trail
                $auditService->logSecurityEvent('webhook_processed', [
                    'webhook_type' => 'conversation_created',
                    'webhook_id' => $this->webhookId,
                    'krayin_lead_id' => $krayinLeadId,
                    'chatwoot_contact_id' => $contact['id'],
                    'chatwoot_conversation_id' => $conversationId,
                    'ip' => $this->ip,
                    'user_agent' => $this->userAgent
                ]);

                Log::info('Conversation created successfully', [
                    'webhook_id' => $this->webhookId,
                    'krayin_lead_id' => $krayinLeadId,
                    'chatwoot_contact_id' => $contact['id'],
                    'chatwoot_conversation_id' => $conversationId,
                    'contact_mapping_id' => $contactMapping->id,
                    'conversation_mapping_id' => $conversationMapping->id
                ]);
            });
        } catch (\Exception $e) {
            Log::error('Failed to process conversation_created webhook', [
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
            'event_type' => 'conversation_created',
            'payload' => json_encode($this->payload),
            'error' => $exception->getMessage(),
            'failed_at' => now(),
            'attempts' => $this->attempts()
        ]);
    }

    /**
     * Build lead data for Krayin API
     */
    private function buildLeadData(array $contact): array
    {
        return [
            'title' => $contact['name'] . ' - Consulta via Chat',
            'person' => [
                'name' => $contact['name'],
                'emails' => $contact['email'] ? [$contact['email']] : [],
                'contact_numbers' => $contact['phone_number'] ? [$contact['phone_number']] : []
            ],
            'lead_pipeline_id' => config('services.krayin.default_pipeline_id', 1),
            'lead_pipeline_stage_id' => config('services.krayin.default_stage_id', 1),
            'custom_fields' => [
                'source' => 'Chatwoot',
                'chatwoot_contact_id' => $contact['id'],
                'created_via_webhook' => true
            ]
        ];
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'webhook',
            'conversation-created',
            'webhook-id:' . $this->webhookId,
            'conversation-id:' . ($this->payload['id'] ?? 'unknown')
        ];
    }
}
