<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Lgpd\ConsentRequest;
use App\Http\Requests\Api\Lgpd\DataDeletionRequest;
use App\Http\Requests\Api\Lgpd\DataExportRequest;
use App\Services\ConsentService;
use App\Services\DataExportService;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ConsentController extends Controller
{
    private ConsentService $consentService;
    private DataExportService $exportService;
    private AuditService $auditService;

    public function __construct(
        ConsentService $consentService,
        DataExportService $exportService,
        AuditService $auditService
    ) {
        $this->consentService = $consentService;
        $this->exportService = $exportService;
        $this->auditService = $auditService;
    }

    /**
     * POST /api/v1/lgpd/consent
     * Record consent for data processing
     */
    public function recordConsent(ConsentRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            $consent = $this->consentService->recordConsent(
                $validated['contact_id'],
                $validated['consent_type'],
                $validated['consent_granted'],
                $request
            );

            // Log audit trail
            $this->auditService->logSecurityEvent('consent_recorded', [
                'contact_id' => $validated['contact_id'],
                'consent_type' => $validated['consent_type'],
                'consent_granted' => $validated['consent_granted'],
                'consent_id' => $consent->id,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);

            return response()->json([
                'success' => true,
                'consent_id' => $consent->id,
                'contact_id' => $validated['contact_id'],
                'consent_type' => $validated['consent_type'],
                'status' => $consent->status,
                'granted_at' => $consent->granted_at?->toIso8601String(),
                'consent_version' => $consent->consent_version,
                'timestamp' => now()->toIso8601String()
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to record consent', [
                'contact_id' => $request->input('contact_id'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to record consent',
                'error_code' => 'CONSENT_RECORDING_FAILED',
                'timestamp' => now()->toIso8601String()
            ], 500);
        }
    }

    /**
     * DELETE /api/v1/lgpd/data/{contact_id}
     * Complete data erasure (LGPD Right to Erasure)
     */
    public function deleteData(DataDeletionRequest $request, int $contactId): JsonResponse
    {
        try {
            $validated = $request->validated();

            // Dispatch data deletion job
            $this->consentService->scheduleDataDeletion(
                $contactId,
                $validated['reason'],
                $request
            );

            // Log audit trail
            $this->auditService->logSecurityEvent('data_deletion_requested', [
                'contact_id' => $contactId,
                'reason' => $validated['reason'],
                'requested_by' => auth()->id(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);

            return response()->json([
                'success' => true,
                'contact_id' => $contactId,
                'status' => 'deletion_scheduled',
                'message' => 'Data deletion has been scheduled and will be processed within 24 hours',
                'timestamp' => now()->toIso8601String()
            ], 202);
        } catch (\Exception $e) {
            Log::error('Failed to schedule data deletion', [
                'contact_id' => $contactId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to schedule data deletion',
                'error_code' => 'DELETION_SCHEDULING_FAILED',
                'timestamp' => now()->toIso8601String()
            ], 500);
        }
    }

    /**
     * GET /api/v1/lgpd/export/{contact_id}
     * Data portability (LGPD Right to Data Portability)
     */
    public function exportData(DataExportRequest $request, int $contactId): JsonResponse
    {
        try {
            // Check if user has permission to export this contact's data
            $this->authorize('export-data', $contactId);

            // Dispatch data export job
            $exportJob = $this->exportService->scheduleDataExport(
                $contactId,
                $request
            );

            // Log audit trail
            $this->auditService->logSecurityEvent('data_export_requested', [
                'contact_id' => $contactId,
                'requested_by' => auth()->id(),
                'export_job_id' => $exportJob->id ?? null,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);

            return response()->json([
                'success' => true,
                'contact_id' => $contactId,
                'status' => 'export_scheduled',
                'message' => 'Data export has been scheduled and will be available within 1 hour',
                'estimated_completion' => now()->addHour()->toIso8601String(),
                'timestamp' => now()->toIso8601String()
            ], 202);
        } catch (\Exception $e) {
            Log::error('Failed to schedule data export', [
                'contact_id' => $contactId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to schedule data export',
                'error_code' => 'EXPORT_SCHEDULING_FAILED',
                'timestamp' => now()->toIso8601String()
            ], 500);
        }
    }

    /**
     * GET /api/v1/lgpd/consent/{contact_id}
     * Get consent status for a contact
     */
    public function getConsentStatus(int $contactId): JsonResponse
    {
        try {
            $consents = $this->consentService->getConsentStatus($contactId);

            return response()->json([
                'success' => true,
                'contact_id' => $contactId,
                'consents' => $consents,
                'timestamp' => now()->toIso8601String()
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to get consent status', [
                'contact_id' => $contactId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve consent status',
                'error_code' => 'CONSENT_RETRIEVAL_FAILED',
                'timestamp' => now()->toIso8601String()
            ], 500);
        }
    }
}
