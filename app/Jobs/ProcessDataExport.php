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

class ProcessDataExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [300, 600, 1800]; // 5min, 10min, 30min
    public $timeout = 1200; // 20 minutes

    private int $contactId;
    private string $format;
    private bool $includeAuditLogs;
    private bool $includeConsentRecords;
    private string $ip;
    private string $userAgent;

    public function __construct(
        int $contactId,
        string $format,
        bool $includeAuditLogs,
        bool $includeConsentRecords,
        string $ip,
        string $userAgent
    ) {
        $this->contactId = $contactId;
        $this->format = $format;
        $this->includeAuditLogs = $includeAuditLogs;
        $this->includeConsentRecords = $includeConsentRecords;
        $this->ip = $ip;
        $this->userAgent = $userAgent;
    }

    public function handle(AuditService $auditService): void
    {
        Log::info('Processing data export', [
            'contact_id' => $this->contactId,
            'format' => $this->format,
            'attempt' => $this->attempts()
        ]);

        try {
            $exportData = $this->gatherExportData();
            $filename = $this->generateExportFile($exportData);
            $downloadUrl = $this->generateDownloadUrl($filename);

            // Log the export
            $auditService->logSecurityEvent('data_export_completed', [
                'contact_id' => $this->contactId,
                'format' => $this->format,
                'filename' => $filename,
                'download_url' => $downloadUrl,
                'ip' => $this->ip,
                'user_agent' => $this->userAgent
            ]);

            Log::info('Data export completed', [
                'contact_id' => $this->contactId,
                'filename' => $filename,
                'download_url' => $downloadUrl
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to process data export', [
                'contact_id' => $this->contactId,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    private function gatherExportData(): array
    {
        $data = [
            'contact' => $this->getContactData(),
            'conversations' => $this->getConversationsData(),
            'messages' => $this->getMessagesData(),
        ];

        if ($this->includeConsentRecords) {
            $data['consent_records'] = $this->getConsentRecordsData();
        }

        if ($this->includeAuditLogs) {
            $data['audit_logs'] = $this->getAuditLogsData();
        }

        return $data;
    }

    private function getContactData(): array
    {
        $contactMapping = ContactMapping::where('chatwoot_contact_id', $this->contactId)->first();

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

    private function getConversationsData(): array
    {
        $conversations = ConversationMapping::whereHas('contactMapping', function ($query) {
            $query->where('chatwoot_contact_id', $this->contactId);
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

    private function getMessagesData(): array
    {
        $conversationIds = ConversationMapping::whereHas('contactMapping', function ($query) {
            $query->where('chatwoot_contact_id', $this->contactId);
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

    private function getConsentRecordsData(): array
    {
        return ConsentRecord::where('contact_id', $this->contactId)
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

    private function getAuditLogsData(): array
    {
        return AuditLog::where('model', 'Contact')
            ->where('model_id', $this->contactId)
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

    private function generateExportFile(array $data): string
    {
        $filename = "data-export-{$this->contactId}-" . now()->format('Y-m-d-H-i-s') . ".{$this->format}";
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
            default:
                $content = json_encode($data, JSON_PRETTY_PRINT);
        }

        Storage::disk('local')->put($filepath, $content);

        return $filename;
    }

    private function generateDownloadUrl(string $filename): string
    {
        // Generate a secure download URL with expiration
        $token = hash('sha256', $filename . now()->timestamp . config('app.key'));
        return url("/api/v1/lgpd/download/{$filename}?token={$token}");
    }

    private function arrayToXml(array $data): string
    {
        $xml = new \SimpleXMLElement('<export></export>');
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
        // Simple CSV conversion - in production, use a proper CSV library
        $output = fopen('php://temp', 'r+');
        $this->arrayToCsvRecursive($data, $output);
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }

    private function arrayToCsvRecursive(array $data, $output): void
    {
        foreach ($data as $row) {
            if (is_array($row)) {
                fputcsv($output, array_values($row));
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical('Data export failed permanently', [
            'contact_id' => $this->contactId,
            'format' => $this->format,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        // Store in failed exports table for manual review
        DB::table('failed_data_exports')->insert([
            'contact_id' => $this->contactId,
            'format' => $this->format,
            'error' => $exception->getMessage(),
            'failed_at' => now(),
            'attempts' => $this->attempts()
        ]);
    }

    public function tags(): array
    {
        return [
            'lgpd',
            'data-export',
            'contact-id:' . $this->contactId
        ];
    }
}
