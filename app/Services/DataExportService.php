<?php

namespace App\Services;

use App\Jobs\ProcessDataExport;
use App\Jobs\ProcessBulkDataExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class DataExportService
{
    /**
     * Schedule data export job
     */
    public function scheduleDataExport(int $contactId, Request $request): object
    {
        $exportJob = ProcessDataExport::dispatch(
            $contactId,
            $request->input('format', 'json'),
            $request->input('include_audit_logs', true),
            $request->input('include_consent_records', true),
            $request->ip(),
            $request->userAgent()
        )->onQueue('lgpd-normal');

        Log::info('Data export scheduled', [
            'contact_id' => $contactId,
            'format' => $request->input('format', 'json'),
            'scheduled_by' => auth()->id()
        ]);

        return $exportJob;
    }

    /**
     * Schedule bulk data export job
     */
    public function scheduleBulkExport(array $contactIds, string $format, bool $includeAuditLogs, bool $includeConsentRecords, Request $request): object
    {
        $exportJob = ProcessBulkDataExport::dispatch(
            $contactIds,
            $format,
            $includeAuditLogs,
            $includeConsentRecords,
            $request->input('include_conversations', true),
            $request->input('include_messages', true),
            $request->ip(),
            $request->userAgent()
        )->onQueue('exports-bulk');

        // Store export job metadata
        $exportId = $exportJob->id ?? uniqid('export_');
        $this->storeExportMetadata($exportId, [
            'contact_ids' => $contactIds,
            'format' => $format,
            'status' => 'scheduled',
            'created_by' => auth()->id(),
            'created_at' => now()->toIso8601String(),
        ]);

        Log::info('Bulk data export scheduled', [
            'contact_count' => count($contactIds),
            'format' => $format,
            'export_id' => $exportId,
            'scheduled_by' => auth()->id()
        ]);

        return $exportJob;
    }

    /**
     * Get export status
     */
    public function getExportStatus(string $exportId): array
    {
        $metadata = $this->getExportMetadata($exportId);

        if (!$metadata) {
            return [
                'export_id' => $exportId,
                'status' => 'not_found',
                'error' => 'Export not found'
            ];
        }

        return [
            'export_id' => $exportId,
            'status' => $metadata['status'] ?? 'unknown',
            'progress' => $metadata['progress'] ?? 0,
            'download_url' => $metadata['download_url'] ?? null,
            'expires_at' => $metadata['expires_at'] ?? null,
            'created_at' => $metadata['created_at'] ?? null,
            'completed_at' => $metadata['completed_at'] ?? null,
            'error' => $metadata['error'] ?? null,
        ];
    }

    /**
     * Get export history for user
     */
    public function getExportHistory(int $userId): array
    {
        // This would typically query a database table
        // For now, return cached data
        $cacheKey = "user_exports:{$userId}";

        return Cache::remember($cacheKey, 300, function () use ($userId) {
            return [
                [
                    'export_id' => 'export_123',
                    'status' => 'completed',
                    'format' => 'json',
                    'contact_count' => 1,
                    'created_at' => now()->subHours(2)->toIso8601String(),
                    'completed_at' => now()->subHour()->toIso8601String(),
                ],
                [
                    'export_id' => 'export_124',
                    'status' => 'processing',
                    'format' => 'csv',
                    'contact_count' => 5,
                    'created_at' => now()->subMinutes(30)->toIso8601String(),
                    'progress' => 60,
                ]
            ];
        });
    }

    /**
     * Cancel export
     */
    public function cancelExport(string $exportId, int $userId): bool
    {
        $metadata = $this->getExportMetadata($exportId);

        if (!$metadata || $metadata['created_by'] !== $userId) {
            return false;
        }

        if (in_array($metadata['status'], ['completed', 'failed', 'cancelled'])) {
            return false;
        }

        $this->updateExportMetadata($exportId, [
            'status' => 'cancelled',
            'cancelled_at' => now()->toIso8601String(),
        ]);

        Log::info('Export cancelled', [
            'export_id' => $exportId,
            'cancelled_by' => $userId
        ]);

        return true;
    }

    /**
     * Validate download token
     */
    public function validateDownloadToken(string $filename, string $token): bool
    {
        $expectedToken = hash('sha256', $filename . now()->format('Y-m-d') . config('app.key'));
        return hash_equals($expectedToken, $token);
    }

    /**
     * Store export metadata
     */
    private function storeExportMetadata(string $exportId, array $metadata): void
    {
        $cacheKey = "export_metadata:{$exportId}";
        Cache::put($cacheKey, $metadata, 86400); // 24 hours
    }

    /**
     * Get export metadata
     */
    private function getExportMetadata(string $exportId): ?array
    {
        $cacheKey = "export_metadata:{$exportId}";
        return Cache::get($cacheKey);
    }

    /**
     * Update export metadata
     */
    private function updateExportMetadata(string $exportId, array $updates): void
    {
        $metadata = $this->getExportMetadata($exportId);
        if ($metadata) {
            $metadata = array_merge($metadata, $updates);
            $this->storeExportMetadata($exportId, $metadata);
        }
    }
}
