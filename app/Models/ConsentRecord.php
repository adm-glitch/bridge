<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Consent Record Model for LGPD Compliance
 * 
 * @package App\Models
 * @author Bridge Service
 * @version 2.1
 */
class ConsentRecord extends Model
{
    use SoftDeletes;

    protected $table = 'consent_records';

    protected $fillable = [
        'contact_id',
        'chatwoot_contact_id',
        'consent_type',
        'status',
        'granted_at',
        'withdrawn_at',
        'expired_at',
        'ip_address',
        'user_agent',
        'consent_text',
        'consent_version',
        'withdrawal_reason',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'withdrawn_at' => 'datetime',
        'expired_at' => 'datetime',
    ];

    protected $dates = [
        'granted_at',
        'withdrawn_at',
        'expired_at',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * Check if consent is valid (granted and not withdrawn/expired)
     */
    public function isValid(): bool
    {
        return $this->status === 'granted'
            && $this->withdrawn_at === null
            && $this->expired_at === null
            && $this->isNotExpired();
    }

    /**
     * Check if consent is not expired based on validity period
     */
    public function isNotExpired(): bool
    {
        $validityDays = config("lgpd.consent_validity_days.{$this->consent_type}", 365);
        $expiryDate = $this->granted_at->addDays($validityDays);

        return now()->isBefore($expiryDate);
    }

    /**
     * Check if consent is active (granted and not withdrawn)
     */
    public function isActive(): bool
    {
        return $this->status === 'granted' && $this->withdrawn_at === null;
    }

    /**
     * Check if consent is withdrawn
     */
    public function isWithdrawn(): bool
    {
        return $this->status === 'withdrawn' && $this->withdrawn_at !== null;
    }

    /**
     * Check if consent is expired
     */
    public function isExpired(): bool
    {
        return $this->status === 'expired' || !$this->isNotExpired();
    }

    /**
     * Get consent age in days
     */
    public function getAgeInDays(): int
    {
        return $this->granted_at->diffInDays(now());
    }

    /**
     * Get days until expiry
     */
    public function getDaysUntilExpiry(): int
    {
        $validityDays = config("lgpd.consent_validity_days.{$this->consent_type}", 365);
        $expiryDate = $this->granted_at->addDays($validityDays);

        return max(0, now()->diffInDays($expiryDate, false));
    }

    /**
     * Scope for active consents
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'granted')
            ->whereNull('withdrawn_at')
            ->whereNull('expired_at');
    }

    /**
     * Scope for valid consents (active and not expired)
     */
    public function scopeValid($query)
    {
        return $query->where('status', 'granted')
            ->whereNull('withdrawn_at')
            ->whereNull('expired_at')
            ->where('granted_at', '>', now()->subDays(365)); // Default validity
    }

    /**
     * Scope for expired consents
     */
    public function scopeExpired($query)
    {
        return $query->where(function ($q) {
            $q->where('status', 'expired')
                ->orWhere('granted_at', '<', now()->subDays(365)); // Default validity
        });
    }

    /**
     * Scope for withdrawn consents
     */
    public function scopeWithdrawn($query)
    {
        return $query->where('status', 'withdrawn')
            ->whereNotNull('withdrawn_at');
    }

    /**
     * Scope for consents by type
     */
    public function scopeByType($query, string $consentType)
    {
        return $query->where('consent_type', $consentType);
    }

    /**
     * Scope for consents by contact
     */
    public function scopeByContact($query, int $contactId)
    {
        return $query->where('contact_id', $contactId);
    }

    /**
     * Scope for consents by Chatwoot contact
     */
    public function scopeByChatwootContact($query, int $chatwootContactId)
    {
        return $query->where('chatwoot_contact_id', $chatwootContactId);
    }

    /**
     * Get consent status with additional context
     */
    public function getStatusWithContext(): array
    {
        $status = $this->status;
        $context = [
            'status' => $status,
            'is_valid' => $this->isValid(),
            'is_active' => $this->isActive(),
            'is_withdrawn' => $this->isWithdrawn(),
            'is_expired' => $this->isExpired(),
            'age_in_days' => $this->getAgeInDays(),
            'days_until_expiry' => $this->getDaysUntilExpiry(),
        ];

        if ($this->isWithdrawn()) {
            $context['withdrawal_reason'] = $this->withdrawal_reason;
            $context['withdrawn_at'] = $this->withdrawn_at;
        }

        if ($this->isExpired()) {
            $context['expired_at'] = $this->expired_at;
        }

        return $context;
    }

    /**
     * Get LGPD compliance status
     */
    public function getLgpdComplianceStatus(): string
    {
        if ($this->isValid()) {
            return 'compliant';
        } elseif ($this->isWithdrawn()) {
            return 'compliant'; // Withdrawn consent is compliant
        } elseif ($this->isExpired()) {
            return 'non_compliant';
        } else {
            return 'unknown';
        }
    }

    /**
     * Get audit trail for this consent
     */
    public function getAuditTrail(): array
    {
        return DB::table('audit_logs')
            ->where('model', 'ConsentRecord')
            ->where('model_id', $this->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
    }

    /**
     * Get consent summary for reporting
     */
    public function getSummary(): array
    {
        return [
            'id' => $this->id,
            'contact_id' => $this->contact_id,
            'chatwoot_contact_id' => $this->chatwoot_contact_id,
            'consent_type' => $this->consent_type,
            'status' => $this->getStatusWithContext(),
            'lgpd_compliance' => $this->getLgpdComplianceStatus(),
            'granted_at' => $this->granted_at,
            'consent_text' => $this->consent_text,
            'consent_version' => $this->consent_version,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
        ];
    }
}
