<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * AiInsight Model
 * 
 * Stores AI-generated insights with historical tracking.
 * Provides time-series design for performance analytics and trend analysis.
 * Supports current and historical insight tracking.
 * 
 * @property int $id
 * @property int $krayin_lead_id
 * @property int $total_conversations
 * @property int $resolved_conversations
 * @property int $pending_conversations
 * @property float $resolution_rate
 * @property int $average_response_time_minutes
 * @property int $total_messages
 * @property float $average_messages_per_conversation
 * @property float $performance_score
 * @property string $engagement_level
 * @property string|null $trend
 * @property array|null $suggestions
 * @property Carbon|null $last_interaction_at
 * @property Carbon $calculated_at
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to
 * @property bool $is_current
 * @property Carbon $created_at
 * 
 * @property-read ContactMapping $contactMapping
 * @property-read ConversationMapping[] $conversations
 * 
 * @package App\Models
 * @author Bridge Service
 * @version 2.1
 */
class AiInsight extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ai_insights';

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
        'krayin_lead_id',
        'total_conversations',
        'resolved_conversations',
        'pending_conversations',
        'resolution_rate',
        'average_response_time_minutes',
        'total_messages',
        'average_messages_per_conversation',
        'performance_score',
        'engagement_level',
        'trend',
        'suggestions',
        'last_interaction_at',
        'calculated_at',
        'valid_from',
        'valid_to',
        'is_current',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'id' => 'integer',
        'krayin_lead_id' => 'integer',
        'total_conversations' => 'integer',
        'resolved_conversations' => 'integer',
        'pending_conversations' => 'integer',
        'resolution_rate' => 'float',
        'average_response_time_minutes' => 'integer',
        'total_messages' => 'integer',
        'average_messages_per_conversation' => 'float',
        'performance_score' => 'float',
        'suggestions' => 'array',
        'last_interaction_at' => 'datetime',
        'calculated_at' => 'datetime',
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
        'is_current' => 'boolean',
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
     * Engagement level constants
     */
    public const ENGAGEMENT_LOW = 'low';
    public const ENGAGEMENT_MEDIUM = 'medium';
    public const ENGAGEMENT_HIGH = 'high';

    /**
     * Trend constants
     */
    public const TREND_IMPROVING = 'improving';
    public const TREND_STABLE = 'stable';
    public const TREND_DECLINING = 'declining';

    /**
     * Performance score constants
     */
    public const SCORE_EXCELLENT = 9.0;
    public const SCORE_GOOD = 7.0;
    public const SCORE_AVERAGE = 5.0;
    public const SCORE_POOR = 3.0;

    /**
     * Cache TTL constants (in seconds)
     */
    private const CACHE_TTL = [
        'insight' => 300,        // 5 minutes
        'current' => 180,        // 3 minutes
        'historical' => 600,     // 10 minutes
        'statistics' => 300,     // 5 minutes
        'leaderboard' => 600,    // 10 minutes
    ];

    /**
     * Cache key prefixes
     */
    private const CACHE_PREFIX = 'ai_insight';

    /**
     * Boot the model.
     * 
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        // Validate engagement level and performance score
        static::creating(function (AiInsight $insight) {
            static::validateInsight($insight);
        });

        static::updating(function (AiInsight $insight) {
            static::validateInsight($insight);
        });

        // Clear related caches when model changes
        static::saved(function (AiInsight $insight) {
            $insight->clearRelatedCaches();
        });

        static::deleted(function (AiInsight $insight) {
            $insight->clearRelatedCaches();
        });
    }

    /**
     * Validate insight data.
     *
     * @param AiInsight $insight
     * @return void
     * @throws \InvalidArgumentException
     */
    private static function validateInsight(AiInsight $insight): void
    {
        // Validate engagement level
        $validEngagements = [
            self::ENGAGEMENT_LOW,
            self::ENGAGEMENT_MEDIUM,
            self::ENGAGEMENT_HIGH,
        ];

        if (!in_array($insight->engagement_level, $validEngagements)) {
            throw new \InvalidArgumentException(
                "Invalid engagement level: {$insight->engagement_level}. Valid levels are: " . implode(', ', $validEngagements)
            );
        }

        // Validate performance score
        if ($insight->performance_score < 0 || $insight->performance_score > 10) {
            throw new \InvalidArgumentException(
                "Performance score must be between 0 and 10, got: {$insight->performance_score}"
            );
        }

        // Validate trend
        if ($insight->trend) {
            $validTrends = [
                self::TREND_IMPROVING,
                self::TREND_STABLE,
                self::TREND_DECLINING,
            ];

            if (!in_array($insight->trend, $validTrends)) {
                throw new \InvalidArgumentException(
                    "Invalid trend: {$insight->trend}. Valid trends are: " . implode(', ', $validTrends)
                );
            }
        }
    }

    /**
     * Get contact mapping for this insight.
     * 
     * @return BelongsTo
     */
    public function contactMapping(): BelongsTo
    {
        return $this->belongsTo('App\Models\ContactMapping', 'krayin_lead_id', 'krayin_lead_id');
    }

    /**
     * Get conversations for this insight.
     * 
     * @return HasMany
     */
    public function conversations(): HasMany
    {
        return $this->hasMany('App\Models\ConversationMapping', 'krayin_lead_id', 'krayin_lead_id');
    }

    /**
     * Scope: Get current insights only.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCurrent($query)
    {
        return $query->where('is_current', true);
    }

    /**
     * Scope: Get historical insights only.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeHistorical($query)
    {
        return $query->where('is_current', false);
    }

    /**
     * Scope: Get insights for a specific lead.
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
     * Scope: Get insights by engagement level.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $level
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByEngagementLevel($query, string $level)
    {
        return $query->where('engagement_level', $level);
    }

    /**
     * Scope: Get insights by trend.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $trend
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByTrend($query, string $trend)
    {
        return $query->where('trend', $trend);
    }

    /**
     * Scope: Get insights with performance score above threshold.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param float $minScore
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithMinScore($query, float $minScore)
    {
        return $query->where('performance_score', '>=', $minScore);
    }

    /**
     * Scope: Get insights with performance score below threshold.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param float $maxScore
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithMaxScore($query, float $maxScore)
    {
        return $query->where('performance_score', '<=', $maxScore);
    }

    /**
     * Scope: Get insights with recent activity.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $days
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithRecentActivity($query, int $days = 7)
    {
        return $query->where('last_interaction_at', '>=', now()->subDays($days));
    }

    /**
     * Scope: Get insights calculated within date range.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCalculatedBetween($query, Carbon $startDate, Carbon $endDate)
    {
        return $query->whereBetween('calculated_at', [$startDate, $endDate]);
    }

    /**
     * Scope: Get insights ordered by performance score.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByPerformanceScore($query)
    {
        return $query->orderBy('performance_score', 'desc');
    }

    /**
     * Scope: Get insights ordered by engagement level.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByEngagement($query)
    {
        return $query->orderBy('engagement_level', 'desc');
    }

    /**
     * Scope: Get insights ordered by trend.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOrderByTrend($query)
    {
        return $query->orderBy('trend', 'desc');
    }

    /**
     * Get current insight for a lead with caching.
     *
     * @param int $leadId
     * @return AiInsight|null
     */
    public static function getCurrentForLead(int $leadId): ?AiInsight
    {
        $cacheKey = static::getCacheKey('current', $leadId);

        return Cache::tags(['ai_insights'])->remember(
            $cacheKey,
            self::CACHE_TTL['current'],
            function () use ($leadId) {
                return static::forLead($leadId)->current()->first();
            }
        );
    }

    /**
     * Get historical insights for a lead with caching.
     *
     * @param int $leadId
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getHistoricalForLead(int $leadId, int $limit = 10)
    {
        $cacheKey = static::getCacheKey('historical', $leadId, $limit);

        return Cache::tags(['ai_insights', "lead:{$leadId}"])->remember(
            $cacheKey,
            self::CACHE_TTL['historical'],
            function () use ($leadId, $limit) {
                return static::forLead($leadId)
                    ->historical()
                    ->orderBy('calculated_at', 'desc')
                    ->limit($limit)
                    ->get();
            }
        );
    }

    /**
     * Get performance leaderboard with caching.
     *
     * @param int $limit
     * @param array $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getPerformanceLeaderboard(int $limit = 10, array $filters = [])
    {
        $cacheKey = static::getCacheKey('leaderboard', $limit, $filters);

        return Cache::tags(['ai_insights'])->remember(
            $cacheKey,
            self::CACHE_TTL['leaderboard'],
            function () use ($limit, $filters) {
                $query = static::current()->byPerformanceScore();

                if (isset($filters['min_score'])) {
                    $query->withMinScore($filters['min_score']);
                }

                if (isset($filters['engagement_level'])) {
                    $query->byEngagementLevel($filters['engagement_level']);
                }

                if (isset($filters['trend'])) {
                    $query->byTrend($filters['trend']);
                }

                return $query->limit($limit)->get();
            }
        );
    }

    /**
     * Get engagement leaderboard with caching.
     *
     * @param int $limit
     * @param array $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getEngagementLeaderboard(int $limit = 10, array $filters = [])
    {
        $cacheKey = static::getCacheKey('engagement_leaderboard', $limit, $filters);

        return Cache::tags(['ai_insights'])->remember(
            $cacheKey,
            self::CACHE_TTL['leaderboard'],
            function () use ($limit, $filters) {
                $query = static::current()->byEngagement();

                if (isset($filters['min_score'])) {
                    $query->withMinScore($filters['min_score']);
                }

                if (isset($filters['trend'])) {
                    $query->byTrend($filters['trend']);
                }

                return $query->limit($limit)->get();
            }
        );
    }

    /**
     * Get insights by engagement level with caching.
     *
     * @param string $level
     * @param array $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getByEngagementLevel(string $level, array $filters = [])
    {
        $cacheKey = static::getCacheKey('engagement_level', $level, $filters);

        return Cache::tags(['ai_insights'])->remember(
            $cacheKey,
            self::CACHE_TTL['insight'],
            function () use ($level, $filters) {
                $query = static::current()->byEngagementLevel($level);

                if (isset($filters['min_score'])) {
                    $query->withMinScore($filters['min_score']);
                }

                if (isset($filters['trend'])) {
                    $query->byTrend($filters['trend']);
                }

                return $query->byPerformanceScore()->get();
            }
        );
    }

    /**
     * Get insights by trend with caching.
     *
     * @param string $trend
     * @param array $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getByTrend(string $trend, array $filters = [])
    {
        $cacheKey = static::getCacheKey('trend', $trend, $filters);

        return Cache::tags(['ai_insights'])->remember(
            $cacheKey,
            self::CACHE_TTL['insight'],
            function () use ($trend, $filters) {
                $query = static::current()->byTrend($trend);

                if (isset($filters['min_score'])) {
                    $query->withMinScore($filters['min_score']);
                }

                if (isset($filters['engagement_level'])) {
                    $query->byEngagementLevel($filters['engagement_level']);
                }

                return $query->byPerformanceScore()->get();
            }
        );
    }

    /**
     * Get insights statistics with caching.
     *
     * @return array
     */
    public static function getInsightsStatistics(): array
    {
        $cacheKey = static::getCacheKey('statistics');

        return Cache::tags(['ai_insights'])->remember(
            $cacheKey,
            self::CACHE_TTL['statistics'],
            function () {
                $current = static::current()->get();

                return [
                    'total_insights' => $current->count(),
                    'average_performance_score' => $current->avg('performance_score'),
                    'high_performance_count' => $current->where('performance_score', '>=', self::SCORE_GOOD)->count(),
                    'low_performance_count' => $current->where('performance_score', '<', self::SCORE_AVERAGE)->count(),
                    'high_engagement_count' => $current->where('engagement_level', self::ENGAGEMENT_HIGH)->count(),
                    'improving_trend_count' => $current->where('trend', self::TREND_IMPROVING)->count(),
                    'declining_trend_count' => $current->where('trend', self::TREND_DECLINING)->count(),
                    'engagement_distribution' => $current->groupBy('engagement_level')->map->count(),
                    'trend_distribution' => $current->groupBy('trend')->map->count(),
                ];
            }
        );
    }

    /**
     * Check if insight has high performance.
     *
     * @return bool
     */
    public function isHighPerformance(): bool
    {
        return $this->performance_score >= self::SCORE_GOOD;
    }

    /**
     * Check if insight has low performance.
     *
     * @return bool
     */
    public function isLowPerformance(): bool
    {
        return $this->performance_score < self::SCORE_AVERAGE;
    }

    /**
     * Check if insight has high engagement.
     *
     * @return bool
     */
    public function isHighEngagement(): bool
    {
        return $this->engagement_level === self::ENGAGEMENT_HIGH;
    }

    /**
     * Check if insight has low engagement.
     *
     * @return bool
     */
    public function isLowEngagement(): bool
    {
        return $this->engagement_level === self::ENGAGEMENT_LOW;
    }

    /**
     * Check if insight is improving.
     *
     * @return bool
     */
    public function isImproving(): bool
    {
        return $this->trend === self::TREND_IMPROVING;
    }

    /**
     * Check if insight is declining.
     *
     * @return bool
     */
    public function isDeclining(): bool
    {
        return $this->trend === self::TREND_DECLINING;
    }

    /**
     * Check if insight is stable.
     *
     * @return bool
     */
    public function isStable(): bool
    {
        return $this->trend === self::TREND_STABLE;
    }

    /**
     * Get performance grade.
     *
     * @return string
     */
    public function getPerformanceGrade(): string
    {
        if ($this->performance_score >= self::SCORE_EXCELLENT) {
            return 'A+';
        } elseif ($this->performance_score >= self::SCORE_GOOD) {
            return 'A';
        } elseif ($this->performance_score >= self::SCORE_AVERAGE) {
            return 'B';
        } elseif ($this->performance_score >= self::SCORE_POOR) {
            return 'C';
        } else {
            return 'D';
        }
    }

    /**
     * Get performance description.
     *
     * @return string
     */
    public function getPerformanceDescription(): string
    {
        if ($this->performance_score >= self::SCORE_EXCELLENT) {
            return 'Excellent';
        } elseif ($this->performance_score >= self::SCORE_GOOD) {
            return 'Good';
        } elseif ($this->performance_score >= self::SCORE_AVERAGE) {
            return 'Average';
        } elseif ($this->performance_score >= self::SCORE_POOR) {
            return 'Below Average';
        } else {
            return 'Poor';
        }
    }

    /**
     * Get engagement description.
     *
     * @return string
     */
    public function getEngagementDescription(): string
    {
        return ucfirst($this->engagement_level);
    }

    /**
     * Get trend description.
     *
     * @return string
     */
    public function getTrendDescription(): string
    {
        if (!$this->trend) {
            return 'No trend data';
        }

        return ucfirst($this->trend);
    }

    /**
     * Get insight summary.
     *
     * @return array
     */
    public function getSummary(): array
    {
        return [
            'id' => $this->id,
            'krayin_lead_id' => $this->krayin_lead_id,
            'performance_score' => $this->performance_score,
            'performance_grade' => $this->getPerformanceGrade(),
            'performance_description' => $this->getPerformanceDescription(),
            'engagement_level' => $this->engagement_level,
            'engagement_description' => $this->getEngagementDescription(),
            'trend' => $this->trend,
            'trend_description' => $this->getTrendDescription(),
            'is_high_performance' => $this->isHighPerformance(),
            'is_low_performance' => $this->isLowPerformance(),
            'is_high_engagement' => $this->isHighEngagement(),
            'is_low_engagement' => $this->isLowEngagement(),
            'is_improving' => $this->isImproving(),
            'is_declining' => $this->isDeclining(),
            'is_stable' => $this->isStable(),
            'total_conversations' => $this->total_conversations,
            'resolved_conversations' => $this->resolved_conversations,
            'resolution_rate' => $this->resolution_rate,
            'average_response_time_minutes' => $this->average_response_time_minutes,
            'total_messages' => $this->total_messages,
            'average_messages_per_conversation' => $this->average_messages_per_conversation,
            'last_interaction_at' => $this->last_interaction_at,
            'calculated_at' => $this->calculated_at,
            'is_current' => $this->is_current,
        ];
    }

    /**
     * Update insights using PostgreSQL function with caching invalidation.
     *
     * @param int $leadId
     * @param array $metrics
     * @return int
     */
    public static function updateInsights(int $leadId, array $metrics): int
    {
        $result = DB::selectOne(
            'SELECT update_ai_insights(?, ?::jsonb) as id',
            [$leadId, json_encode($metrics)]
        );

        // Clear related caches
        static::clearLeadCaches($leadId);

        return $result->id;
    }

    /**
     * Create or update insight with caching invalidation.
     *
     * @param array $attributes
     * @return AiInsight
     */
    public static function createOrUpdateInsight(array $attributes): AiInsight
    {
        $insight = static::updateOrCreate(
            [
                'krayin_lead_id' => $attributes['krayin_lead_id'],
                'is_current' => true
            ],
            $attributes
        );

        // Clear related caches
        $insight->clearRelatedCaches();

        return $insight;
    }

    /**
     * Clear all caches related to this insight.
     *
     * @return void
     */
    public function clearRelatedCaches(): void
    {
        // Clear insight caches
        Cache::tags(['ai_insights'])->forget(
            static::getCacheKey('current', $this->krayin_lead_id)
        );

        Cache::tags(['ai_insights', "lead:{$this->krayin_lead_id}"])->flush();

        // Clear leaderboard caches
        Cache::tags(['ai_insights'])->flush();
    }

    /**
     * Clear caches for a specific lead.
     *
     * @param int $leadId
     * @return void
     */
    public static function clearLeadCaches(int $leadId): void
    {
        Cache::tags(['ai_insights'])->forget(
            static::getCacheKey('current', $leadId)
        );

        Cache::tags(['ai_insights', "lead:{$leadId}"])->flush();
    }

    /**
     * Clear all AI insight caches.
     *
     * @return void
     */
    public static function clearAllCaches(): void
    {
        Cache::tags(['ai_insights'])->flush();
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
     * Get all valid engagement levels.
     *
     * @return array
     */
    public static function getValidEngagementLevels(): array
    {
        return [
            self::ENGAGEMENT_LOW,
            self::ENGAGEMENT_MEDIUM,
            self::ENGAGEMENT_HIGH,
        ];
    }

    /**
     * Get all valid trends.
     *
     * @return array
     */
    public static function getValidTrends(): array
    {
        return [
            self::TREND_IMPROVING,
            self::TREND_STABLE,
            self::TREND_DECLINING,
        ];
    }

    /**
     * Get performance score ranges.
     *
     * @return array
     */
    public static function getPerformanceScoreRanges(): array
    {
        return [
            'excellent' => [self::SCORE_EXCELLENT, 10.0],
            'good' => [self::SCORE_GOOD, self::SCORE_EXCELLENT],
            'average' => [self::SCORE_AVERAGE, self::SCORE_GOOD],
            'poor' => [self::SCORE_POOR, self::SCORE_AVERAGE],
            'very_poor' => [0.0, self::SCORE_POOR],
        ];
    }
}
