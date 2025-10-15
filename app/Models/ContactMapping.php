<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Exceptions\InvalidKrayinLeadException;
use Carbon\Carbon;

/**
 * ContactMapping Model
 * 
 * Maps Chatwoot contacts to Krayin leads and persons.
 * Provides bi-directional mapping between Chatwoot and Krayin systems.
 * 
 * @property int $id
 * @property int $chatwoot_contact_id
 * @property int|null $krayin_lead_id
 * @property int|null $krayin_person_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * 
 * @property-read ConversationMapping[] $conversations
 * @property-read ActivityMapping[] $activities
 * 
 * @package App\Models
 * @author Bridge Service
 * @version 2.1
 */
class ContactMapping extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'contact_mappings';

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
        'chatwoot_contact_id',
        'krayin_lead_id',
        'krayin_person_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'id' => 'integer',
        'chatwoot_contact_id' => 'integer',
        'krayin_lead_id' => 'integer',
        'krayin_person_id' => 'integer',
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
     * Cache TTL constants (in seconds)
     */
    private const CACHE_TTL = [
        'mapping' => 300,        // 5 minutes
        'conversations' => 180,  // 3 minutes
        'activities' => 120,     // 2 minutes
    ];

    /**
     * Cache key prefixes
     */
    private const CACHE_PREFIX = 'contact_mapping';

    /**
     * Boot the model.
     * 
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        // Validate Krayin lead exists before creating/updating
        static::creating(function (ContactMapping $mapping) {
            static::validateKrayinLead($mapping);
        });

        static::updating(function (ContactMapping $mapping) {
            static::validateKrayinLead($mapping);
        });

        // Clear related caches when model changes
        static::saved(function (ContactMapping $mapping) {
            $mapping->clearRelatedCaches();
        });

        static::deleted(function (ContactMapping $mapping) {
            $mapping->clearRelatedCaches();
        });
    }

    /**
     * Validate that Krayin lead exists if provided.
     *
     * @param ContactMapping $mapping
     * @return void
     * @throws InvalidKrayinLeadException
     */
    private static function validateKrayinLead(ContactMapping $mapping): void
    {
        if ($mapping->krayin_lead_id) {
            // Note: KrayinApiService validation will be implemented when the service is available
            // For now, we'll skip validation to avoid dependency issues
            // TODO: Implement Krayin lead validation when KrayinApiService is available
        }
    }

    /**
     * Get conversations for this contact mapping.
     * 
     * @return HasMany
     */
    public function conversations(): HasMany
    {
        return $this->hasMany('App\Models\ConversationMapping', 'krayin_lead_id', 'krayin_lead_id');
    }

    /**
     * Get activities for this contact mapping.
     * 
     * @return HasMany
     */
    public function activities(): HasMany
    {
        return $this->hasMany('App\Models\ActivityMapping', 'krayin_lead_id', 'krayin_lead_id');
    }

    /**
     * Scope: Get mappings by Chatwoot contact ID.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $chatwootContactId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByChatwootContact($query, int $chatwootContactId)
    {
        return $query->where('chatwoot_contact_id', $chatwootContactId);
    }

    /**
     * Scope: Get mappings by Krayin lead ID.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $krayinLeadId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByKrayinLead($query, int $krayinLeadId)
    {
        return $query->where('krayin_lead_id', $krayinLeadId);
    }

    /**
     * Scope: Get mappings by Krayin person ID.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $krayinPersonId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByKrayinPerson($query, int $krayinPersonId)
    {
        return $query->where('krayin_person_id', $krayinPersonId);
    }

    /**
     * Scope: Get mappings with leads only (exclude person-only mappings).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithLeads($query)
    {
        return $query->whereNotNull('krayin_lead_id');
    }

    /**
     * Scope: Get mappings with persons only (exclude lead-only mappings).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithPersons($query)
    {
        return $query->whereNotNull('krayin_person_id');
    }

    /**
     * Scope: Get mappings created within date range.
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
     * Scope: Get recent mappings.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $days
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Get mapping by Chatwoot contact ID with caching.
     *
     * @param int $chatwootContactId
     * @return ContactMapping|null
     */
    public static function getByChatwootContactId(int $chatwootContactId): ?ContactMapping
    {
        $cacheKey = static::getCacheKey('chatwoot', $chatwootContactId);

        return Cache::tags(['contact_mappings'])->remember(
            $cacheKey,
            self::CACHE_TTL['mapping'],
            function () use ($chatwootContactId) {
                return static::byChatwootContact($chatwootContactId)->first();
            }
        );
    }

    /**
     * Get mapping by Krayin lead ID with caching.
     *
     * @param int $krayinLeadId
     * @return ContactMapping|null
     */
    public static function getByKrayinLeadId(int $krayinLeadId): ?ContactMapping
    {
        $cacheKey = static::getCacheKey('krayin_lead', $krayinLeadId);

        return Cache::tags(['contact_mappings'])->remember(
            $cacheKey,
            self::CACHE_TTL['mapping'],
            function () use ($krayinLeadId) {
                return static::byKrayinLead($krayinLeadId)->first();
            }
        );
    }

    /**
     * Get mapping by Krayin person ID with caching.
     *
     * @param int $krayinPersonId
     * @return ContactMapping|null
     */
    public static function getByKrayinPersonId(int $krayinPersonId): ?ContactMapping
    {
        $cacheKey = static::getCacheKey('krayin_person', $krayinPersonId);

        return Cache::tags(['contact_mappings'])->remember(
            $cacheKey,
            self::CACHE_TTL['mapping'],
            function () use ($krayinPersonId) {
                return static::byKrayinPerson($krayinPersonId)->first();
            }
        );
    }

    /**
     * Get conversations for this mapping with caching.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getCachedConversations()
    {
        $cacheKey = static::getCacheKey('conversations', $this->id);

        return Cache::tags(['contact_mappings', "mapping:{$this->id}"])->remember(
            $cacheKey,
            self::CACHE_TTL['conversations'],
            function () {
                return $this->conversations()->orderBy('updated_at', 'desc')->get();
            }
        );
    }

    /**
     * Get activities for this mapping with caching.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getCachedActivities()
    {
        $cacheKey = static::getCacheKey('activities', $this->id);

        return Cache::tags(['contact_mappings', "mapping:{$this->id}"])->remember(
            $cacheKey,
            self::CACHE_TTL['activities'],
            function () {
                return $this->activities()->orderBy('created_at', 'desc')->get();
            }
        );
    }

    /**
     * Check if mapping has active conversations.
     *
     * @return bool
     */
    public function hasActiveConversations(): bool
    {
        $cacheKey = static::getCacheKey('active_conversations', $this->id);

        return Cache::tags(['contact_mappings', "mapping:{$this->id}"])->remember(
            $cacheKey,
            self::CACHE_TTL['conversations'],
            function () {
                return $this->conversations()
                    ->where('status', 'open')
                    ->exists();
            }
        );
    }

    /**
     * Get conversation count for this mapping with caching.
     *
     * @return int
     */
    public function getConversationCount(): int
    {
        $cacheKey = static::getCacheKey('conversation_count', $this->id);

        return Cache::tags(['contact_mappings', "mapping:{$this->id}"])->remember(
            $cacheKey,
            self::CACHE_TTL['conversations'],
            function () {
                return $this->conversations()->count();
            }
        );
    }

    /**
     * Get activity count for this mapping with caching.
     *
     * @return int
     */
    public function getActivityCount(): int
    {
        $cacheKey = static::getCacheKey('activity_count', $this->id);

        return Cache::tags(['contact_mappings', "mapping:{$this->id}"])->remember(
            $cacheKey,
            self::CACHE_TTL['activities'],
            function () {
                return $this->activities()->count();
            }
        );
    }

    /**
     * Create or update mapping with caching invalidation.
     *
     * @param array $attributes
     * @return ContactMapping
     */
    public static function createOrUpdateMapping(array $attributes): ContactMapping
    {
        $mapping = static::updateOrCreate(
            ['chatwoot_contact_id' => $attributes['chatwoot_contact_id']],
            $attributes
        );

        // Clear related caches
        $mapping->clearRelatedCaches();

        return $mapping;
    }

    /**
     * Clear all caches related to this mapping.
     *
     * @return void
     */
    public function clearRelatedCaches(): void
    {
        // Clear mapping caches
        Cache::tags(['contact_mappings'])->forget(
            static::getCacheKey('chatwoot', $this->chatwoot_contact_id)
        );

        if ($this->krayin_lead_id) {
            Cache::tags(['contact_mappings'])->forget(
                static::getCacheKey('krayin_lead', $this->krayin_lead_id)
            );
        }

        if ($this->krayin_person_id) {
            Cache::tags(['contact_mappings'])->forget(
                static::getCacheKey('krayin_person', $this->krayin_person_id)
            );
        }

        // Clear mapping-specific caches
        Cache::tags(["mapping:{$this->id}"])->flush();
    }

    /**
     * Clear all contact mapping caches.
     *
     * @return void
     */
    public static function clearAllCaches(): void
    {
        Cache::tags(['contact_mappings'])->flush();
    }

    /**
     * Get cache key for specific mapping type and ID.
     *
     * @param string $type
     * @param int $id
     * @return string
     */
    private static function getCacheKey(string $type, int $id): string
    {
        return self::CACHE_PREFIX . ":{$type}:{$id}";
    }

    /**
     * Check if this mapping has a Krayin lead.
     *
     * @return bool
     */
    public function hasKrayinLead(): bool
    {
        return !is_null($this->krayin_lead_id);
    }

    /**
     * Check if this mapping has a Krayin person.
     *
     * @return bool
     */
    public function hasKrayinPerson(): bool
    {
        return !is_null($this->krayin_person_id);
    }

    /**
     * Get the primary Krayin entity ID (lead takes precedence over person).
     *
     * @return int|null
     */
    public function getPrimaryKrayinId(): ?int
    {
        return $this->krayin_lead_id ?? $this->krayin_person_id;
    }

    /**
     * Get the primary Krayin entity type.
     *
     * @return string|null
     */
    public function getPrimaryKrayinType(): ?string
    {
        if ($this->krayin_lead_id) {
            return 'lead';
        }

        if ($this->krayin_person_id) {
            return 'person';
        }

        return null;
    }

    /**
     * Get mapping statistics with caching.
     *
     * @return array
     */
    public function getStatistics(): array
    {
        $cacheKey = static::getCacheKey('statistics', $this->id);

        return Cache::tags(['contact_mappings', "mapping:{$this->id}"])->remember(
            $cacheKey,
            self::CACHE_TTL['mapping'],
            function () {
                return [
                    'conversation_count' => $this->getConversationCount(),
                    'activity_count' => $this->getActivityCount(),
                    'has_active_conversations' => $this->hasActiveConversations(),
                    'primary_krayin_id' => $this->getPrimaryKrayinId(),
                    'primary_krayin_type' => $this->getPrimaryKrayinType(),
                    'created_at' => $this->created_at,
                    'updated_at' => $this->updated_at,
                ];
            }
        );
    }
}
