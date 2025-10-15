<?php

namespace App\Jobs;

use App\Models\ConsentRecord;
use App\Models\ContactMapping;
use App\Models\ConversationMapping;
use App\Models\ActivityMapping;
use App\Models\AuditLog;
use App\Services\AuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessDataDeletion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [300, 600, 1800]; // 5min, 10min, 30min
    public $timeout = 600; // 10 minutes

    private int $contactId;
    private string $reason;
    private string $ip;
    private string $userAgent;

    public function __construct(int $contactId, string $reason, string $ip, string $userAgent)
    {
        $this->contactId = $contactId;
        $this->reason = $reason;
        $this->ip = $ip;
        $this->userAgent = $userAgent;
    }

    public function handle(AuditService $auditService): void
    {
        Log::info('Processing data deletion', [
            'contact_id' => $this->contactId,
            'reason' => $this->reason,
            'attempt' => $this->attempts()
        ]);

        try {
            DB::transaction(function () use ($auditService) {
                $deletedCounts = [
                    'conversations' => 0,
                    'messages' => 0,
                    'activities' => 0,
                    'consent_records' => 0,
                    'audit_logs' => 0
                ];

                // Get conversation mappings for this contact
                $conversationMappings = ConversationMapping::whereHas('contactMapping', function ($query) {
                    $query->where('chatwoot_contact_id', $this->contactId);
                })->get();

                // Delete activity mappings (messages)
                foreach ($conversationMappings as $conversation) {
                    $activityCount = ActivityMapping::where('chatwoot_conversation_id', $conversation->chatwoot_conversation_id)->count();
                    ActivityMapping::where('chatwoot_conversation_id', $conversation->chatwoot_conversation_id)->delete();
                    $deletedCounts['messages'] += $activityCount;
                }

                // Delete conversation mappings
                $deletedCounts['conversations'] = $conversationMappings->count();
                $conversationMappings->each->delete();

                // Delete contact mappings
                $contactMappings = ContactMapping::where('chatwoot_contact_id', $this->contactId)->get();
                $contactMappings->each->delete();

                // Delete consent records
                $deletedCounts['consent_records'] = ConsentRecord::where('contact_id', $this->contactId)->count();
                ConsentRecord::where('contact_id', $this->contactId)->delete();

                // Delete audit logs for this contact
                $deletedCounts['audit_logs'] = AuditLog::where('model', 'Contact')
                    ->where('model_id', $this->contactId)
                    ->count();
                AuditLog::where('model', 'Contact')
                    ->where('model_id', $this->contactId)
                    ->delete();

                // Log the deletion
                $auditService->logSecurityEvent('data_deletion_completed', [
                    'contact_id' => $this->contactId,
                    'reason' => $this->reason,
                    'deleted_counts' => $deletedCounts,
                    'anonymization_applied' => true,
                    'ip' => $this->ip,
                    'user_agent' => $this->userAgent
                ]);

                Log::info('Data deletion completed', [
                    'contact_id' => $this->contactId,
                    'deleted_counts' => $deletedCounts
                ]);
            });

        } catch (\Exception $e) {
            Log::error('Failed to process data deletion', [
                'contact_id' => $this->contactId,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical('Data deletion failed permanently', [
            'contact_id' => $this->contactId,
            'reason' => $this->reason,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        // Store in failed deletions table for manual review
        DB::table('failed_data_deletions')->insert([
            'contact_id' => $this->contactId,
            'reason' => $this->reason,
            'error' => $exception->getMessage(),
            'failed_at' => now(),
            'attempts' => $this->attempts()
        ]);
    }

    public function tags(): array
    {
        return [
            'lgpd',
            'data-deletion',
            'contact-id:' . $this->contactId
        ];
    }
}
