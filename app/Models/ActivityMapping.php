<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * ActivityMapping Model
 * 
 * Maps Chatwoot messages to Krayin activities.
 * Provides message tracking with performance metrics and type management.
 * Supports partitioned table structure for high-volume data.
 * 
 * @property int $id
 * @property int $chatwoot_message_id
 * @property int $krayin_activity_id
 * @property int $conversation_id
 * @property string $message_type
 * @property Carbon $created_at
 * 
 * @property-read ConversationMapping $conversation
 * @property-read ContactMapping $contactMapping
 * 
 * @package App\Models
 * @author Bridge Service
 * @version 2.1
 */
class ActivityMapping extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'activity_mappings';

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
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'chatwoot_message_id',
        'krayin_activity_id',
        'conversation_id',
        'message_type',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'id' => 'integer',
        'chatwoot_message_id' => 'integer',
        'krayin_activity_id' => 'integer',
        'conversation_id' => 'integer',
        'created_at' => 'datetime',
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
     * Message type constants
     */
    public const TYPE_INCOMING = 'incoming';
    public const TYPE_OUTGOING = 'outgoing';
    public const TYPE_ACTIVITY = 'activity';

    /**
     * Cache TTL constants (in seconds)
     */
    private const CACHE_TTL = [
        'mapping' => 300,        // 5 minutes
        'activities' => 180,     // 3 minutes
        'conversation' => 120,   // 2 minutes
        'statistics' => 300,     // 5 minutes
    ];

    /**
     * Cache key prefixes
     */
    private const CACHE_PREFIX = 'activity_mapping';

    /**
     * Boot the model.
     * 
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        // Validate message type
        static::creating(function (ActivityMapping $mapping) {
            static::validateMessageType($mapping);
        });

        static::updating(function (ActivityMapping $mapping) {
            static::validateMessageType($mapping);
        });

        // Clear related caches when model changes
        static::saved(function (ActivityMapping $mapping) {
            $mapping->clearRelatedCaches();
        });

        static::deleted(function (ActivityMapping $mapping) {
            $mapping->clearRelatedCaches();
        });
    }

    /**
     * Validate message type.
     *
     * @param ActivityMapping $mapping
     * @return void
     * @throws \InvalidArgumentException
     */
    private static function validateMessageType(ActivityMapping $mapping): void
    {
        $validTypes = [
            self::TYPE_INCOMING,
            self::TYPE_OUTGOING,
            self::TYPE_ACTIVITY,
        ];

        if (!in_array($mapping->message_type, $validTypes)) {
            throw new \InvalidArgumentException(
                "Invalid message type: {$mapping->message_type}. Valid types are: " . implode(', ', $validTypes)
            );
        }
    }

    /**
     * Get conversation for this activity mapping.
     * 
     * @return BelongsTo
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo('App\Models\ConversationMapping', 'conversation_id', 'chatwoot_conversation_id');
    }

    /**
     * Get contact mapping for this activity mapping.
     * 
     * @return BelongsTo
     */
    public function contactMapping(): BelongsTo
    {
        return $this->belongsTo('App\Models\ContactMapping', 'conversation_id', 'chatwoot_conversation_id');
    }

    /**
     * Scope: Get activities by message type.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $type
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByMessageType($query, string $type)
    {
        return $query->where('message_type', $type);
    }

    /**
     * Scope: Get incoming messages.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeIncoming($query)
    {
        return $query->where('message_type', self::TYPE_INCOMING);
    }

    /**
     * Scope: Get outgoing messages.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOutgoing($query)
    {
        return $query->where('message_type', self::TYPE_OUTGOING);
    }

    /**
     * Scope: Get activity messages.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActivity($query)
    {
        return $query->where('message_type', self::TYPE_ACTIVITY);
    }

    /**
     * Scope: Get activities for a specific conversation.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $conversationId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForConversation($query, int $conversationId)
    {
        return $query->where('conversation_id', $conversationId);
    }

    /**
     * Scope: Get activities by Chatwoot message ID.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $messageId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByChatwootMessage($query, int $messageId)
    {
        return $query->where('chatwoot_message_id', $messageId);
    }

    /**
     * Scope: Get activities by Krayin activity ID.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $activityId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByKrayinActivity($query, int $activityId)
    {
        return $query->where('krayin_activity_id', $activityId);
    }

    /**
     * Scope: Get activities created within date range.
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
     * Scope: Get recent activities.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $hours
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }

    /**
     * Scope: Get activities ordered by creation time.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeLatestFirst($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Scope: Get activities ordered by creation time (oldest first).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOldestFirst($query)
    {
        return $query->orderBy('created_at', 'asc');
    }

    /**
     * Scope: Get activities for a specific lead.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $leadId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForLead($query, int $leadId)
    {
        return $query->whereHas('conversation', function ($q) use ($leadId) {
            $q->where('krayin_lead_id', $leadId);
        });
    }

    /**
     * Get activity by Chatwoot message ID with caching.
     *
     * @param int $chatwootMessageId
     * @return ActivityMapping|null
     */
    public static function getByChatwootMessageId(int $chatwootMessageId): ?ActivityMapping
    {
        $cacheKey = static::getCacheKey('chatwoot_message', $chatwootMessageId);

        return Cache::tags(['activity_mappings'])->remember(
            $cacheKey,
            self::CACHE_TTL['mapping'],
            function () use ($chatwootMessageId) {
                return static::byChatwootMessage($chatwootMessageId)->first();
            }
        );
    }

    /**
     * Get activities for a conversation with caching.
     *
     * @param int $conversationId
     * @param array $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getActivitiesForConversation(int $conversationId, array $filters = [])
    {
        $cacheKey = static::getCacheKey('conversation_activities', $conversationId, $filters);

        return Cache::tags(['activity_mappings', "conversation:{$conversationId}"])->remember(
            $cacheKey,
            self::CACHE_TTL['activities'],
            function () use ($conversationId, $filters) {
                $query = static::forConversation($conversationId);

                if (isset($filters['message_type'])) {
                    $query->byMessageType($filters['message_type']);
                }

                if (isset($filters['recent_hours'])) {
                    $query->recent($filters['recent_hours']);
                }

                if (isset($filters['order'])) {
                    if ($filters['order'] === 'asc') {
                        $query->oldestFirst();
                    } else {
                        $query->latestFirst();
                    }
                } else {
                    $query->latestFirst();
                }

                return $query->get();
            }
        );
    }

    /**
     * Get activities for a lead with caching.
     *
     * @param int $leadId
     * @param array $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getActivitiesForLead(int $leadId, array $filters = [])
    {
        $cacheKey = static::getCacheKey('lead_activities', $leadId, $filters);

        return Cache::tags(['activity_mappings', "lead:{$leadId}"])->remember(
            $cacheKey,
            self::CACHE_TTL['activities'],
            function () use ($leadId, $filters) {
                $query = static::forLead($leadId);

                if (isset($filters['message_type'])) {
                    $query->byMessageType($filters['message_type']);
                }

                if (isset($filters['recent_hours'])) {
                    $query->recent($filters['recent_hours']);
                }

                if (isset($filters['order'])) {
                    if ($filters['order'] === 'asc') {
                        $query->oldestFirst();
                    } else {
                        $query->latestFirst();
                    }
                } else {
                    $query->latestFirst();
                }

                return $query->get();
            }
        );
    }

    /**
     * Get recent activities with caching.
     *
     * @param int $hours
     * @param array $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getRecentActivities(int $hours = 24, array $filters = [])
    {
        $cacheKey = static::getCacheKey('recent_activities', $hours, $filters);

        return Cache::tags(['activity_mappings'])->remember(
            $cacheKey,
            self::CACHE_TTL['activities'],
            function () use ($hours, $filters) {
                $query = static::recent($hours);

                if (isset($filters['message_type'])) {
                    $query->byMessageType($filters['message_type']);
                }

                if (isset($filters['conversation_id'])) {
                    $query->forConversation($filters['conversation_id']);
                }

                return $query->latestFirst()->get();
            }
        );
    }

    /**
     * Check if activity is incoming message.
     *
     * @return bool
     */
    public function isIncoming(): bool
    {
        return $this->message_type === self::TYPE_INCOMING;
    }

    /**
     * Check if activity is outgoing message.
     *
     * @return bool
     */
    public function isOutgoing(): bool
    {
        return $this->message_type === self::TYPE_OUTGOING;
    }

    /**
     * Check if activity is system activity.
     *
     * @return bool
     */
    public function isActivity(): bool
    {
        return $this->message_type === self::TYPE_ACTIVITY;
    }

    /**
     * Check if activity is recent.
     *
     * @param int $hours
     * @return bool
     */
    public function isRecent(int $hours = 24): bool
    {
        return $this->created_at->isAfter(now()->subHours($hours));
    }

    /**
     * Get activity age in minutes.
     *
     * @return int
     */
    public function getAgeMinutes(): int
    {
        return $this->created_at->diffInMinutes(now());
    }

    /**
     * Get activity age in hours.
     *
     * @return int
     */
    public function getAgeHours(): int
    {
        return $this->created_at->diffInHours(now());
    }

    /**
     * Get activity age in days.
     *
     * @return int
     */
    public function getAgeDays(): int
    {
        return $this->created_at->diffInDays(now());
    }

    /**
     * Get activity statistics for a conversation with caching.
     *
     * @param int $conversationId
     * @return array
     */
    public static function getConversationStatistics(int $conversationId): array
    {
        $cacheKey = static::getCacheKey('conversation_stats', $conversationId);

        return Cache::tags(['activity_mappings', "conversation:{$conversationId}"])->remember(
            $cacheKey,
            self::CACHE_TTL['statistics'],
            function () use ($conversationId) {
                $activities = static::forConversation($conversationId)->get();

                return [
                    'total_activities' => $activities->count(),
                    'incoming_count' => $activities->where('message_type', self::TYPE_INCOMING)->count(),
                    'outgoing_count' => $activities->where('message_type', self::TYPE_OUTGOING)->count(),
                    'activity_count' => $activities->where('message_type', self::TYPE_ACTIVITY)->count(),
                    'latest_activity' => $activities->max('created_at'),
                    'oldest_activity' => $activities->min('created_at'),
                    'conversation_id' => $conversationId,
                ];
            }
        );
    }

    /**
     * Get activity statistics for a lead with caching.
     *
     * @param int $leadId
     * @return array
     */
    public static function getLeadStatistics(int $leadId): array
    {
        $cacheKey = static::getCacheKey('lead_stats', $leadId);

        return Cache::tags(['activity_mappings', "lead:{$leadId}"])->remember(
            $cacheKey,
            self::CACHE_TTL['statistics'],
            function () use ($leadId) {
                $activities = static::forLead($leadId)->get();

                return [
                    'total_activities' => $activities->count(),
                    'incoming_count' => $activities->where('message_type', self::TYPE_INCOMING)->count(),
                    'outgoing_count' => $activities->where('message_type', self::TYPE_OUTGOING)->count(),
                    'activity_count' => $activities->where('message_type', self::TYPE_ACTIVITY)->count(),
                    'latest_activity' => $activities->max('created_at'),
                    'oldest_activity' => $activities->min('created_at'),
                    'lead_id' => $leadId,
                ];
            }
        );
    }

    /**
     * Create or update activity mapping with caching invalidation.
     *
     * @param array $attributes
     * @return ActivityMapping
     */
    public static function createOrUpdateMapping(array $attributes): ActivityMapping
    {
        $mapping = static::updateOrCreate(
            ['chatwoot_message_id' => $attributes['chatwoot_message_id']],
            $attributes
        );

        // Clear related caches
        $mapping->clearRelatedCaches();

        return $mapping;
    }

    /**
     * Clear all caches related to this activity.
     *
     * @return void
     */
    public function clearRelatedCaches(): void
    {
        // Clear activity caches
        Cache::tags(['activity_mappings'])->forget(
            static::getCacheKey('chatwoot_message', $this->chatwoot_message_id)
        );

        Cache::tags(['activity_mappings', "conversation:{$this->conversation_id}"])->flush();

        // Clear lead caches if conversation exists
        if ($this->conversation) {
            Cache::tags(['activity_mappings', "lead:{$this->conversation->krayin_lead_id}"])->flush();
        }
    }

    /**
     * Clear all activity mapping caches.
     *
     * @return void
     */
    public static function clearAllCaches(): void
    {
        Cache::tags(['activity_mappings'])->flush();
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
     * Get all valid message types.
     *
     * @return array
     */
    public static function getValidMessageTypes(): array
    {
        return [
            self::TYPE_INCOMING,
            self::TYPE_OUTGOING,
            self::TYPE_ACTIVITY,
        ];
    }

    /**
     * Get message type display name.
     *
     * @return string
     */
    public function getMessageTypeDisplayAttribute(): string
    {
        return ucfirst(str_replace('_', ' ', $this->message_type));
    }

    /**
     * Get activity age display.
     *
     * @return string
     */
    public function getAgeDisplayAttribute(): string
    {
        $age = $this->getAgeMinutes();

        if ($age < 60) {
            return "{$age} minutes ago";
        } elseif ($age < 1440) {
            $hours = floor($age / 60);
            return "{$hours} hours ago";
        } else {
            $days = floor($age / 1440);
            return "{$days} days ago";
        }
    }

    /**
     * Get activity summary for display.
     *
     * @return array
     */
    public function getSummary(): array
    {
        return [
            'id' => $this->id,
            'chatwoot_message_id' => $this->chatwoot_message_id,
            'krayin_activity_id' => $this->krayin_activity_id,
            'conversation_id' => $this->conversation_id,
            'message_type' => $this->message_type,
            'message_type_display' => $this->message_type_display,
            'is_incoming' => $this->isIncoming(),
            'is_outgoing' => $this->isOutgoing(),
            'is_activity' => $this->isActivity(),
            'is_recent' => $this->isRecent(),
            'age_minutes' => $this->getAgeMinutes(),
            'age_display' => $this->age_display,
            'created_at' => $this->created_at,
        ];
    }
}
