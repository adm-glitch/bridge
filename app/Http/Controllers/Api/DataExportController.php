<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Export\BulkExportRequest;
use App\Http\Requests\Api\Export\ExportStatusRequest;
use App\Services\DataExportService;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DataExportController extends Controller
{
    private DataExportService $exportService;
    private AuditService $auditService;

    public function __construct(
        DataExportService $exportService,
        AuditService $auditService
    ) {
        $this->exportService = $exportService;
        $this->auditService = $auditService;
    }

    /**
     * POST /api/v1/export/bulk
     * Schedule bulk data export for multiple contacts
     */
    public function bulkExport(BulkExportRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            $exportJob = $this->exportService->scheduleBulkExport(
                $validated['contact_ids'],
                $validated['format'],
                $validated['include_audit_logs'] ?? true,
                $validated['include_consent_records'] ?? true,
                $request
            );

            // Log audit trail
            $this->auditService->logSecurityEvent('bulk_export_requested', [
                'contact_count' => count($validated['contact_ids']),
                'format' => $validated['format'],
                'export_job_id' => $exportJob->id ?? null,
                'requested_by' => auth()->id(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);

            return response()->json([
                'success' => true,
                'export_job_id' => $exportJob->id ?? null,
                'contact_count' => count($validated['contact_ids']),
                'format' => $validated['format'],
                'status' => 'export_scheduled',
                'message' => 'Bulk export has been scheduled and will be available within 2 hours',
                'estimated_completion' => now()->addHours(2)->toIso8601String(),
                'timestamp' => now()->toIso8601String()
            ], 202);
        } catch (\Exception $e) {
            Log::error('Failed to schedule bulk export', [
                'contact_ids' => $request->input('contact_ids'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to schedule bulk export',
                'error_code' => 'BULK_EXPORT_SCHEDULING_FAILED',
                'timestamp' => now()->toIso8601String()
            ], 500);
        }
    }

    /**
     * GET /api/v1/export/status/{export_id}
     * Get export status and download URL
     */
    public function getExportStatus(ExportStatusRequest $request, string $exportId): JsonResponse
    {
        try {
            $status = $this->exportService->getExportStatus($exportId);

            return response()->json([
                'success' => true,
                'export_id' => $exportId,
                'status' => $status['status'],
                'progress' => $status['progress'] ?? 0,
                'download_url' => $status['download_url'] ?? null,
                'expires_at' => $status['expires_at'] ?? null,
                'created_at' => $status['created_at'] ?? null,
                'completed_at' => $status['completed_at'] ?? null,
                'timestamp' => now()->toIso8601String()
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to get export status', [
                'export_id' => $exportId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve export status',
                'error_code' => 'EXPORT_STATUS_RETRIEVAL_FAILED',
                'timestamp' => now()->toIso8601String()
            ], 500);
        }
    }

    /**
     * GET /api/v1/export/download/{filename}
     * Download exported data file
     */
    public function downloadExport(string $filename)
    {
        try {
            // Verify download token and permissions
            $token = request()->query('token');
            if (!$this->exportService->validateDownloadToken($filename, $token)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid or expired download token',
                    'error_code' => 'INVALID_DOWNLOAD_TOKEN',
                    'timestamp' => now()->toIso8601String()
                ], 403);
            }

            $filePath = "exports/{$filename}";
            if (!Storage::disk('local')->exists($filePath)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Export file not found',
                    'error_code' => 'EXPORT_FILE_NOT_FOUND',
                    'timestamp' => now()->toIso8601String()
                ], 404);
            }

            // Log download access
            $this->auditService->logSecurityEvent('export_downloaded', [
                'filename' => $filename,
                'downloaded_by' => auth()->id(),
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent()
            ]);

            $fileContent = Storage::disk('local')->get($filePath);
            $mimeType = $this->getMimeType($filename);

            return response($fileContent)
                ->header('Content-Type', $mimeType)
                ->header('Content-Disposition', "attachment; filename=\"{$filename}\"")
                ->header('Cache-Control', 'private, max-age=3600');
        } catch (\Exception $e) {
            Log::error('Failed to download export', [
                'filename' => $filename,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to download export file',
                'error_code' => 'EXPORT_DOWNLOAD_FAILED',
                'timestamp' => now()->toIso8601String()
            ], 500);
        }
    }

    /**
     * GET /api/v1/export/history
     * Get export history for current user
     */
    public function getExportHistory(): JsonResponse
    {
        try {
            $history = $this->exportService->getExportHistory(auth()->id());

            return response()->json([
                'success' => true,
                'exports' => $history,
                'timestamp' => now()->toIso8601String()
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to get export history', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve export history',
                'error_code' => 'EXPORT_HISTORY_RETRIEVAL_FAILED',
                'timestamp' => now()->toIso8601String()
            ], 500);
        }
    }

    /**
     * DELETE /api/v1/export/{export_id}
     * Cancel or delete an export
     */
    public function cancelExport(string $exportId): JsonResponse
    {
        try {
            $cancelled = $this->exportService->cancelExport($exportId, auth()->id());

            if (!$cancelled) {
                return response()->json([
                    'success' => false,
                    'error' => 'Export not found or cannot be cancelled',
                    'error_code' => 'EXPORT_CANCELLATION_FAILED',
                    'timestamp' => now()->toIso8601String()
                ], 404);
            }

            // Log cancellation
            $this->auditService->logSecurityEvent('export_cancelled', [
                'export_id' => $exportId,
                'cancelled_by' => auth()->id(),
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent()
            ]);

            return response()->json([
                'success' => true,
                'export_id' => $exportId,
                'status' => 'cancelled',
                'message' => 'Export has been cancelled successfully',
                'timestamp' => now()->toIso8601String()
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to cancel export', [
                'export_id' => $exportId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to cancel export',
                'error_code' => 'EXPORT_CANCELLATION_FAILED',
                'timestamp' => now()->toIso8601String()
            ], 500);
        }
    }

    private function getMimeType(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($extension) {
            'json' => 'application/json',
            'xml' => 'application/xml',
            'csv' => 'text/csv',
            'zip' => 'application/zip',
            default => 'application/octet-stream',
        };
    }
}
