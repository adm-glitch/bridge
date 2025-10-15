<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * ConversationMapping Model
 * 
 * Maps Chatwoot conversations to Krayin leads.
 * Provides conversation tracking with performance metrics and status management.
 * 
 * @property int $id
 * @property int $chatwoot_conversation_id
 * @property int $krayin_lead_id
 * @property string $status
 * @property int $message_count
 * @property Carbon|null $last_message_at
 * @property Carbon|null $first_response_at
 * @property Carbon|null $resolved_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * 
 * @property-read ContactMapping $contactMapping
 * @property-read ActivityMapping[] $activities
 * 
 * @package App\Models
 * @author Bridge Service
 * @version 2.1
 */
class ConversationMapping extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'conversation_mappings';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'chatwoot_conversation_id',
        'krayin_lead_id',
        'status',
        'message_count',
        'last_message_at',
        'first_response_at',
        'resolved_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'id' => 'integer',
        'chatwoot_conversation_id' => 'integer',
        'krayin_lead_id' => 'integer',
        'message_count' => 'integer',
        'last_message_at' => 'datetime',
        'first_response_at' => 'datetime',
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [];

    /**
     * The connection name for the model.
     * Forces writes to primary database for consistency.
     *
     * @var string
     */
    protected $connection = 'pgsql_write';

    /**
     * Conversation status constants
     */
    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_PENDING = 'pending';
    public const STATUS_SNOOZED = 'snoozed';

    /**
     * Cache TTL constants (in seconds)
     */
    private const CACHE_TTL = [
        'mapping' => 300,        // 5 minutes
        'conversations' => 180,  // 3 minutes
        'activities' => 120,     // 2 minutes
        'statistics' => 300,     // 5 minutes
    ];

    /**
     * Cache key prefixes
     */
    private const CACHE_PREFIX = 'conversation_mapping';

    /**
     * Boot the model.
     * 
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        // Validate status values
        static::creating(function (ConversationMapping $mapping) {
            static::validateStatus($mapping);
        });

        static::updating(function (ConversationMapping $mapping) {
            static::validateStatus($mapping);

            // Auto-set resolved_at when status changes to resolved
            if ($mapping->isDirty('status') && $mapping->status === self::STATUS_RESOLVED && !$mapping->resolved_at) {
                $mapping->resolved_at = now();
            }
        });

        // Clear related caches when model changes
        static::saved(function (ConversationMapping $mapping) {
            $mapping->clearRelatedCaches();
        });

        static::deleted(function (ConversationMapping $mapping) {
            $mapping->clearRelatedCaches();
        });
    }

    /**
     * Validate conversation status.
     *
     * @param ConversationMapping $mapping
     * @return void
     * @throws \InvalidArgumentException
     */
    private static function validateStatus(ConversationMapping $mapping): void
    {
        $validStatuses = [
            self::STATUS_OPEN,
            self::STATUS_RESOLVED,
            self::STATUS_PENDING,
            self::STATUS_SNOOZED,
        ];

        if (!in_array($mapping->status, $validStatuses)) {
            throw new \InvalidArgumentException(
                "Invalid conversation status: {$mapping->status}. Valid statuses are: " . implode(', ', $validStatuses)
            );
        }
    }

    /**
     * Get contact mapping for this conversation.
     * 
     * @return BelongsTo
     */
    public function contactMapping(): BelongsTo
    {
        return $this->belongsTo('App\Models\ContactMapping', 'krayin_lead_id', 'krayin_lead_id');
    }

    /**
     * Get activities for this conversation.
     * 
     * @return HasMany
     */
    public function activities(): HasMany
    {
        return $this->hasMany('App\Models\ActivityMapping', 'conversation_id', 'chatwoot_conversation_id');
    }

    /**
     * Scope: Get conversations by status.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $status
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope: Get open conversations.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /**
     * Scope: Get resolved conversations.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeResolved($query)
    {
        return $query->where('status', self::STATUS_RESOLVED);
    }

    /**
     * Scope: Get pending conversations.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope: Get snoozed conversations.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSnoozed($query)
    {
        return $query->where('status', self::STATUS_SNOOZED);
    }

    /**
     * Scope: Get conversations for a specific lead.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $leadId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForLead($query, int $leadId)
    {
        return $query->where('krayin_lead_id', $leadId);
    }

    /**
     * Scope: Get conversations by Chatwoot conversation ID.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $conversationId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByChatwootConversation($query, int $conversationId)
    {
        return $query->where('chatwoot_conversation_id', $conversationId);
    }

    /**
     * Scope: Get conversations with recent activity.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $hours
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRecentActivity($query, int $hours = 24)
    {
        return $query->where('last_message_at', '>=', now()->subHours($hours));
    }

    /**
     * Scope: Get conversations created within date range.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCreatedBetween($query, Carbon $startDate, Carbon $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Scope: Get conversations with message count above threshold.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $minMessages
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithMinMessages($query, int $minMessages)
    {
        return $query->where('message_count', '>=', $minMessages);
    }

    /**
     * Scope: Get conversations ordered by recent activity.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRecentFirst($query)
    {
        return $query->orderBy('updated_at', 'desc');
    }

    /**
     * Scope: Get conversations ordered by last message.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByLastMessage($query)
    {
        return $query->orderBy('last_message_at', 'desc');
    }

    /**
     * Get conversation by Chatwoot conversation ID with caching.
     *
     * @param int $chatwootConversationId
     * @return ConversationMapping|null
     */
    public static function getByChatwootConversationId(int $chatwootConversationId): ?ConversationMapping
    {
        $cacheKey = static::getCacheKey('chatwoot', $chatwootConversationId);

        return Cache::tags(['conversation_mappings'])->remember(
            $cacheKey,
            self::CACHE_TTL['mapping'],
            function () use ($chatwootConversationId) {
                return static::byChatwootConversation($chatwootConversationId)->first();
            }
        );
    }

    /**
     * Get conversations for a lead with caching.
     *
     * @param int $leadId
     * @param array $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getConversationsForLead(int $leadId, array $filters = [])
    {
        $cacheKey = static::getCacheKey('lead_conversations', $leadId, $filters);

        return Cache::tags(['conversation_mappings', "lead:{$leadId}"])->remember(
            $cacheKey,
            self::CACHE_TTL['conversations'],
            function () use ($leadId, $filters) {
                $query = static::forLead($leadId);

                if (isset($filters['status'])) {
                    $query->where('status', $filters['status']);
                }

                if (isset($filters['min_messages'])) {
                    $query->withMinMessages($filters['min_messages']);
                }

                if (isset($filters['recent_hours'])) {
                    $query->recentActivity($filters['recent_hours']);
                }

                return $query->recentFirst()->get();
            }
        );
    }

    /**
     * Get open conversations for a lead with caching.
     *
     * @param int $leadId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getOpenConversationsForLead(int $leadId)
    {
        $cacheKey = static::getCacheKey('open_conversations', $leadId);

        return Cache::tags(['conversation_mappings', "lead:{$leadId}"])->remember(
            $cacheKey,
            self::CACHE_TTL['conversations'],
            function () use ($leadId) {
                return static::forLead($leadId)->open()->recentFirst()->get();
            }
        );
    }

    /**
     * Get activities for this conversation with caching.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getCachedActivities()
    {
        $cacheKey = static::getCacheKey('activities', $this->id);

        return Cache::tags(['conversation_mappings', "conversation:{$this->id}"])->remember(
            $cacheKey,
            self::CACHE_TTL['activities'],
            function () {
                return $this->activities()->orderBy('created_at', 'desc')->get();
            }
        );
    }

    /**
     * Check if conversation has recent activity.
     *
     * @param int $hours
     * @return bool
     */
    public function hasRecentActivity(int $hours = 24): bool
    {
        if (!$this->last_message_at) {
            return false;
        }

        return $this->last_message_at->isAfter(now()->subHours($hours));
    }

    /**
     * Check if conversation is active (open or pending).
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_PENDING]);
    }

    /**
     * Check if conversation is resolved.
     *
     * @return bool
     */
    public function isResolved(): bool
    {
        return $this->status === self::STATUS_RESOLVED;
    }

    /**
     * Check if conversation is open.
     *
     * @return bool
     */
    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /**
     * Check if conversation is pending.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if conversation is snoozed.
     *
     * @return bool
     */
    public function isSnoozed(): bool
    {
        return $this->status === self::STATUS_SNOOZED;
    }

    /**
     * Get conversation duration in minutes.
     *
     * @return int|null
     */
    public function getDurationMinutes(): ?int
    {
        if (!$this->first_response_at) {
            return null;
        }

        $endTime = $this->resolved_at ?? now();
        return $this->first_response_at->diffInMinutes($endTime);
    }

    /**
     * Get response time in minutes.
     *
     * @return int|null
     */
    public function getResponseTimeMinutes(): ?int
    {
        if (!$this->first_response_at) {
            return null;
        }

        return $this->created_at->diffInMinutes($this->first_response_at);
    }

    /**
     * Update conversation status with automatic timestamp handling.
     *
     * @param string $status
     * @return bool
     */
    public function updateStatus(string $status): bool
    {
        $this->status = $status;

        if ($status === self::STATUS_RESOLVED && !$this->resolved_at) {
            $this->resolved_at = now();
        }

        return $this->save();
    }

    /**
     * Mark conversation as resolved.
     *
     * @return bool
     */
    public function markAsResolved(): bool
    {
        return $this->updateStatus(self::STATUS_RESOLVED);
    }

    /**
     * Mark conversation as open.
     *
     * @return bool
     */
    public function markAsOpen(): bool
    {
        return $this->updateStatus(self::STATUS_OPEN);
    }

    /**
     * Update message count and last message timestamp.
     *
     * @param int $count
     * @param Carbon|null $timestamp
     * @return bool
     */
    public function updateMessageCount(int $count, ?Carbon $timestamp = null): bool
    {
        $this->message_count = $count;

        if ($timestamp) {
            $this->last_message_at = $timestamp;
        }

        return $this->save();
    }

    /**
     * Set first response timestamp if not already set.
     *
     * @param Carbon|null $timestamp
     * @return bool
     */
    public function setFirstResponse(?Carbon $timestamp = null): bool
    {
        if (!$this->first_response_at) {
            $this->first_response_at = $timestamp ?? now();
            return $this->save();
        }

        return true;
    }

    /**
     * Get conversation statistics with caching.
     *
     * @return array
     */
    public function getStatistics(): array
    {
        $cacheKey = static::getCacheKey('statistics', $this->id);

        return Cache::tags(['conversation_mappings', "conversation:{$this->id}"])->remember(
            $cacheKey,
            self::CACHE_TTL['statistics'],
            function () {
                return [
                    'id' => $this->id,
                    'chatwoot_conversation_id' => $this->chatwoot_conversation_id,
                    'krayin_lead_id' => $this->krayin_lead_id,
                    'status' => $this->status,
                    'message_count' => $this->message_count,
                    'is_active' => $this->isActive(),
                    'is_resolved' => $this->isResolved(),
                    'has_recent_activity' => $this->hasRecentActivity(),
                    'duration_minutes' => $this->getDurationMinutes(),
                    'response_time_minutes' => $this->getResponseTimeMinutes(),
                    'created_at' => $this->created_at,
                    'updated_at' => $this->updated_at,
                    'last_message_at' => $this->last_message_at,
                    'first_response_at' => $this->first_response_at,
                    'resolved_at' => $this->resolved_at,
                ];
            }
        );
    }

    /**
     * Create or update conversation mapping with caching invalidation.
     *
     * @param array $attributes
     * @return ConversationMapping
     */
    public static function createOrUpdateMapping(array $attributes): ConversationMapping
    {
        $mapping = static::updateOrCreate(
            ['chatwoot_conversation_id' => $attributes['chatwoot_conversation_id']],
            $attributes
        );

        // Clear related caches
        $mapping->clearRelatedCaches();

        return $mapping;
    }

    /**
     * Clear all caches related to this conversation.
     *
     * @return void
     */
    public function clearRelatedCaches(): void
    {
        // Clear conversation caches
        Cache::tags(['conversation_mappings'])->forget(
            static::getCacheKey('chatwoot', $this->chatwoot_conversation_id)
        );

        Cache::tags(['conversation_mappings', "lead:{$this->krayin_lead_id}"])->flush();
        Cache::tags(["conversation:{$this->id}"])->flush();
    }

    /**
     * Clear all conversation mapping caches.
     *
     * @return void
     */
    public static function clearAllCaches(): void
    {
        Cache::tags(['conversation_mappings'])->flush();
    }

    /**
     * Get cache key for specific mapping type and parameters.
     *
     * @param string $type
     * @param mixed ...$params
     * @return string
     */
    private static function getCacheKey(string $type, ...$params): string
    {
        $key = self::CACHE_PREFIX . ":{$type}";

        foreach ($params as $param) {
            if (is_array($param)) {
                $key .= ':' . md5(serialize($param));
            } else {
                $key .= ":{$param}";
            }
        }

        return $key;
    }

    /**
     * Get all valid status values.
     *
     * @return array
     */
    public static function getValidStatuses(): array
    {
        return [
            self::STATUS_OPEN,
            self::STATUS_RESOLVED,
            self::STATUS_PENDING,
            self::STATUS_SNOOZED,
        ];
    }

    /**
     * Get status display name.
     *
     * @return string
     */
    public function getStatusDisplayAttribute(): string
    {
        return ucfirst(str_replace('_', ' ', $this->status));
    }
}
