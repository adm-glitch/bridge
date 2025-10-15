<?php

namespace App\Jobs;

use App\Models\AuditLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ProcessAuditLog implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [60, 120, 300]; // Exponential backoff: 1min, 2min, 5min
    public $timeout = 30;
    public $maxExceptions = 2;

    private array $auditData;

    /**
     * Create a new job instance.
     */
    public function __construct(array $auditData)
    {
        $this->auditData = $auditData;

        // Set queue based on priority
        if (isset($auditData['changes']['security_event']) && $auditData['changes']['security_event']) {
            $this->onQueue('audit-logs-high');
        } else {
            $this->onQueue('audit-logs');
        }
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            DB::transaction(function () {
                AuditLog::create($this->auditData);
            });

            Log::debug('Audit log processed successfully', [
                'user_id' => $this->auditData['user_id'],
                'action' => $this->auditData['action'],
                'model' => $this->auditData['model']
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to process audit log', [
                'audit_data' => $this->auditData,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts()
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
        Log::critical('Audit log processing failed permanently', [
            'audit_data' => $this->auditData,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        // Store in dead letter queue for manual review
        DB::table('failed_audit_logs')->insert([
            'audit_data' => json_encode($this->auditData),
            'error' => $exception->getMessage(),
            'failed_at' => now(),
            'attempts' => $this->attempts()
        ]);
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'audit-log',
            'user:' . ($this->auditData['user_id'] ?? 'anonymous'),
            'action:' . $this->auditData['action']
        ];
    }
}
