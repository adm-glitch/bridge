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
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class ProcessBulkDataExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [300, 600, 1800]; // 5min, 10min, 30min
    public $timeout = 3600; // 1 hour

    private array $contactIds;
    private string $format;
    private bool $includeAuditLogs;
    private bool $includeConsentRecords;
    private bool $includeConversations;
    private bool $includeMessages;
    private string $ip;
    private string $userAgent;

    public function __construct(
        array $contactIds,
        string $format,
        bool $includeAuditLogs,
        bool $includeConsentRecords,
        bool $includeConversations,
        bool $includeMessages,
        string $ip,
        string $userAgent
    ) {
        $this->contactIds = $contactIds;
        $this->format = $format;
        $this->includeAuditLogs = $includeAuditLogs;
        $this->includeConsentRecords = $includeConsentRecords;
        $this->includeConversations = $includeConversations;
        $this->includeMessages = $includeMessages;
        $this->ip = $ip;
        $this->userAgent = $userAgent;
    }

    public function handle(AuditService $auditService): void
    {
        Log::info('Processing bulk data export', [
            'contact_count' => count($this->contactIds),
            'format' => $this->format,
            'attempt' => $this->attempts()
        ]);

        try {
            $exportData = $this->gatherBulkExportData();
            $filename = $this->generateBulkExportFile($exportData);
            $downloadUrl = $this->generateDownloadUrl($filename);

            // Update export metadata
            $this->updateExportMetadata([
                'status' => 'completed',
                'progress' => 100,
                'download_url' => $downloadUrl,
                'filename' => $filename,
                'completed_at' => now()->toIso8601String(),
                'expires_at' => now()->addDays(7)->toIso8601String(),
            ]);

            // Log the export
            $auditService->logSecurityEvent('bulk_export_completed', [
                'contact_count' => count($this->contactIds),
                'format' => $this->format,
                'filename' => $filename,
                'download_url' => $downloadUrl,
                'ip' => $this->ip,
                'user_agent' => $this->userAgent
            ]);

            Log::info('Bulk data export completed', [
                'contact_count' => count($this->contactIds),
                'filename' => $filename,
                'download_url' => $downloadUrl
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to process bulk data export', [
                'contact_count' => count($this->contactIds),
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->updateExportMetadata([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'failed_at' => now()->toIso8601String(),
            ]);

            throw $e;
        }
    }

    private function gatherBulkExportData(): array
    {
        $bulkData = [
            'export_info' => [
                'contact_count' => count($this->contactIds),
                'format' => $this->format,
                'exported_at' => now()->toIso8601String(),
                'includes' => [
                    'audit_logs' => $this->includeAuditLogs,
                    'consent_records' => $this->includeConsentRecords,
                    'conversations' => $this->includeConversations,
                    'messages' => $this->includeMessages,
                ]
            ],
            'contacts' => []
        ];

        foreach ($this->contactIds as $contactId) {
            $contactData = $this->gatherContactData($contactId);
            if (!empty($contactData)) {
                $bulkData['contacts'][] = $contactData;
            }
        }

        return $bulkData;
    }

    private function gatherContactData(int $contactId): array
    {
        $contactData = [
            'contact_id' => $contactId,
            'contact' => $this->getContactData($contactId),
        ];

        if ($this->includeConversations) {
            $contactData['conversations'] = $this->getConversationsData($contactId);
        }

        if ($this->includeMessages) {
            $contactData['messages'] = $this->getMessagesData($contactId);
        }

        if ($this->includeConsentRecords) {
            $contactData['consent_records'] = $this->getConsentRecordsData($contactId);
        }

        if ($this->includeAuditLogs) {
            $contactData['audit_logs'] = $this->getAuditLogsData($contactId);
        }

        return $contactData;
    }

    private function getContactData(int $contactId): array
    {
        $contactMapping = ContactMapping::where('chatwoot_contact_id', $contactId)->first();

        if (!$contactMapping) {
            return [];
        }

        return [
            'chatwoot_contact_id' => $contactMapping->chatwoot_contact_id,
            'krayin_lead_id' => $contactMapping->krayin_lead_id,
            'contact_name' => $contactMapping->contact_name,
            'contact_email' => $contactMapping->contact_email,
            'contact_phone' => $contactMapping->contact_phone,
            'created_at' => $contactMapping->created_at?->toIso8601String(),
        ];
    }

    private function getConversationsData(int $contactId): array
    {
        $conversations = ConversationMapping::whereHas('contactMapping', function ($query) use ($contactId) {
            $query->where('chatwoot_contact_id', $contactId);
        })->get();

        return $conversations->map(function ($conversation) {
            return [
                'chatwoot_conversation_id' => $conversation->chatwoot_conversation_id,
                'krayin_lead_id' => $conversation->krayin_lead_id,
                'status' => $conversation->status,
                'created_at' => $conversation->created_at?->toIso8601String(),
                'updated_at' => $conversation->updated_at?->toIso8601String(),
            ];
        })->toArray();
    }

    private function getMessagesData(int $contactId): array
    {
        $conversationIds = ConversationMapping::whereHas('contactMapping', function ($query) use ($contactId) {
            $query->where('chatwoot_contact_id', $contactId);
        })->pluck('chatwoot_conversation_id');

        $activities = ActivityMapping::whereIn('chatwoot_conversation_id', $conversationIds)->get();

        return $activities->map(function ($activity) {
            return [
                'chatwoot_message_id' => $activity->chatwoot_message_id,
                'krayin_activity_id' => $activity->krayin_activity_id,
                'message_type' => $activity->message_type,
                'content_type' => $activity->content_type,
                'content' => $activity->content,
                'sender_name' => $activity->sender_name,
                'sender_type' => $activity->sender_type,
                'created_at' => $activity->created_at?->toIso8601String(),
            ];
        })->toArray();
    }

    private function getConsentRecordsData(int $contactId): array
    {
        return ConsentRecord::where('contact_id', $contactId)
            ->get()
            ->map(function ($consent) {
                return [
                    'consent_type' => $consent->consent_type,
                    'status' => $consent->status,
                    'granted_at' => $consent->granted_at?->toIso8601String(),
                    'withdrawn_at' => $consent->withdrawn_at?->toIso8601String(),
                    'consent_text' => $consent->consent_text,
                    'consent_version' => $consent->consent_version,
                    'created_at' => $consent->created_at?->toIso8601String(),
                ];
            })->toArray();
    }

    private function getAuditLogsData(int $contactId): array
    {
        return AuditLog::where('model', 'Contact')
            ->where('model_id', $contactId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($log) {
                return [
                    'action' => $log->action,
                    'changes' => $log->changes,
                    'ip_address' => $log->ip_address,
                    'user_agent' => $log->user_agent,
                    'created_at' => $log->created_at?->toIso8601String(),
                ];
            })->toArray();
    }

    private function generateBulkExportFile(array $data): string
    {
        $filename = "bulk-export-" . now()->format('Y-m-d-H-i-s') . ".{$this->format}";
        $filepath = "exports/{$filename}";

        switch ($this->format) {
            case 'json':
                $content = json_encode($data, JSON_PRETTY_PRINT);
                break;
            case 'xml':
                $content = $this->arrayToXml($data);
                break;
            case 'csv':
                $content = $this->arrayToCsv($data);
                break;
            case 'zip':
                $content = $this->arrayToZip($data);
                break;
            default:
                $content = json_encode($data, JSON_PRETTY_PRINT);
        }

        Storage::disk('local')->put($filepath, $content);

        return $filename;
    }

    private function generateDownloadUrl(string $filename): string
    {
        $token = hash('sha256', $filename . now()->timestamp . config('app.key'));
        return url("/api/v1/export/download/{$filename}?token={$token}");
    }

    private function arrayToXml(array $data): string
    {
        $xml = new \SimpleXMLElement('<bulk_export></bulk_export>');
        $this->arrayToXmlRecursive($data, $xml);
        return $xml->asXML();
    }

    private function arrayToXmlRecursive(array $data, \SimpleXMLElement $xml): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $child = $xml->addChild($key);
                $this->arrayToXmlRecursive($value, $child);
            } else {
                $xml->addChild($key, htmlspecialchars($value));
            }
        }
    }

    private function arrayToCsv(array $data): string
    {
        $output = fopen('php://temp', 'r+');

        // Write header
        fputcsv($output, ['Contact ID', 'Contact Name', 'Email', 'Phone', 'Conversations', 'Messages']);

        foreach ($data['contacts'] as $contact) {
            $contactInfo = $contact['contact'] ?? [];
            $conversationCount = count($contact['conversations'] ?? []);
            $messageCount = count($contact['messages'] ?? []);

            fputcsv($output, [
                $contactInfo['chatwoot_contact_id'] ?? '',
                $contactInfo['contact_name'] ?? '',
                $contactInfo['contact_email'] ?? '',
                $contactInfo['contact_phone'] ?? '',
                $conversationCount,
                $messageCount,
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }

    private function arrayToZip(array $data): string
    {
        $zip = new ZipArchive();
        $tempFile = tempnam(sys_get_temp_dir(), 'bulk_export_');

        if ($zip->open($tempFile, ZipArchive::CREATE) !== TRUE) {
            throw new \Exception('Cannot create ZIP file');
        }

        // Add JSON file
        $zip->addFromString('export_data.json', json_encode($data, JSON_PRETTY_PRINT));

        // Add CSV file
        $zip->addFromString('export_data.csv', $this->arrayToCsv($data));

        // Add XML file
        $zip->addFromString('export_data.xml', $this->arrayToXml($data));

        $zip->close();

        $content = file_get_contents($tempFile);
        unlink($tempFile);

        return $content;
    }

    private function updateExportMetadata(array $updates): void
    {
        // This would typically update a database table
        // For now, we'll use cache
        $exportId = $this->job->getJobId();
        $cacheKey = "export_metadata:{$exportId}";

        $metadata = cache()->get($cacheKey, []);
        $metadata = array_merge($metadata, $updates);
        cache()->put($cacheKey, $metadata, 86400);
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical('Bulk data export failed permanently', [
            'contact_count' => count($this->contactIds),
            'format' => $this->format,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        $this->updateExportMetadata([
            'status' => 'failed',
            'error' => $exception->getMessage(),
            'failed_at' => now()->toIso8601String(),
        ]);
    }

    public function tags(): array
    {
        return [
            'bulk-export',
            'format:' . $this->format,
            'contacts:' . count($this->contactIds)
        ];
    }
}
