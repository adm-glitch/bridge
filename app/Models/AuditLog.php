<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * AuditLog Model
 * 
 * Complete audit trail for LGPD compliance.
 * Tracks all data access, modifications, and exports with comprehensive logging.
 * Supports partitioned table structure for high-volume audit data.
 * 
 * @property int $id
 * @property int|null $user_id
 * @property string $action
 * @property string $model
 * @property int|null $model_id
 * @property array|null $changes
 * @property string $ip_address
 * @property string $user_agent
 * @property Carbon $created_at
 * 
 * @property-read User|null $user
 * @property-read Model|null $auditable
 * 
 * @package App\Models
 * @author Bridge Service
 * @version 2.1
 */
class AuditLog extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'audit_logs';

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
        'user_id',
        'action',
        'model',
        'model_id',
        'changes',
        'ip_address',
        'user_agent',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'model_id' => 'integer',
        'changes' => 'array',
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
     * Action constants
     */
    public const ACTION_CREATE = 'create';
    public const ACTION_READ = 'read';
    public const ACTION_UPDATE = 'update';
    public const ACTION_DELETE = 'delete';
    public const ACTION_EXPORT = 'export';
    public const ACTION_LOGIN = 'login';
    public const ACTION_LOGOUT = 'logout';
    public const ACTION_ACCESS = 'access';

    /**
     * Model constants
     */
    public const MODEL_CONTACT = 'Contact';
    public const MODEL_LEAD = 'Lead';
    public const MODEL_CONVERSATION = 'Conversation';
    public const MODEL_ACTIVITY = 'Activity';
    public const MODEL_CONSENT = 'ConsentRecord';
    public const MODEL_USER = 'User';

    /**
     * Cache TTL constants (in seconds)
     */
    private const CACHE_TTL = [
        'audit' => 300,        // 5 minutes
        'user' => 180,         // 3 minutes
        'model' => 300,        // 5 minutes
        'statistics' => 600,   // 10 minutes
        'reports' => 900,      // 15 minutes
    ];

    /**
     * Cache key prefixes
     */
    private const CACHE_PREFIX = 'audit_log';

    /**
     * Boot the model.
     * 
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        // Validate audit data
        static::creating(function (AuditLog $audit) {
            static::validateAudit($audit);
        });

        static::updating(function (AuditLog $audit) {
            static::validateAudit($audit);
        });

        // Clear related caches when model changes
        static::saved(function (AuditLog $audit) {
            $audit->clearRelatedCaches();
        });

        static::deleted(function (AuditLog $audit) {
            $audit->clearRelatedCaches();
        });
    }

    /**
     * Validate audit data.
     *
     * @param AuditLog $audit
     * @return void
     * @throws \InvalidArgumentException
     */
    private static function validateAudit(AuditLog $audit): void
    {
        // Validate action
        $validActions = [
            self::ACTION_CREATE,
            self::ACTION_READ,
            self::ACTION_UPDATE,
            self::ACTION_DELETE,
            self::ACTION_EXPORT,
            self::ACTION_LOGIN,
            self::ACTION_LOGOUT,
            self::ACTION_ACCESS,
        ];

        if (!in_array($audit->action, $validActions)) {
            throw new \InvalidArgumentException(
                "Invalid action: {$audit->action}. Valid actions are: " . implode(', ', $validActions)
            );
        }

        // Validate model name
        if (empty($audit->model)) {
            throw new \InvalidArgumentException('Model name is required');
        }

        // Validate IP address format
        if (!filter_var($audit->ip_address, FILTER_VALIDATE_IP)) {
            throw new \InvalidArgumentException("Invalid IP address: {$audit->ip_address}");
        }

        // Validate user agent
        if (empty($audit->user_agent)) {
            throw new \InvalidArgumentException('User agent is required');
        }
    }

    /**
     * Get user for this audit log.
     * 
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo('App\Models\User', 'user_id', 'id');
    }

    /**
     * Get the auditable model instance.
     * 
     * @return Model|null
     */
    public function getAuditableAttribute(): ?Model
    {
        if (!$this->model || !$this->model_id) {
            return null;
        }

        $modelClass = "App\\Models\\{$this->model}";

        if (!class_exists($modelClass)) {
            return null;
        }

        return $modelClass::find($this->model_id);
    }

    /**
     * Scope: Get audit logs by action.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $action
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Scope: Get audit logs by model.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $model
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByModel($query, string $model)
    {
        return $query->where('model', $model);
    }

    /**
     * Scope: Get audit logs by user.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $userId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: Get audit logs by model ID.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $modelId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByModelId($query, int $modelId)
    {
        return $query->where('model_id', $modelId);
    }

    /**
     * Scope: Get audit logs by IP address.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $ipAddress
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByIpAddress($query, string $ipAddress)
    {
        return $query->where('ip_address', $ipAddress);
    }

    /**
     * Scope: Get audit logs created within date range.
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
     * Scope: Get recent audit logs.
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
     * Scope: Get audit logs ordered by creation time.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeLatestFirst($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Scope: Get audit logs with changes.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithChanges($query)
    {
        return $query->whereNotNull('changes');
    }

    /**
     * Scope: Get audit logs without changes.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithoutChanges($query)
    {
        return $query->whereNull('changes');
    }

    /**
     * Scope: Get audit logs for specific model and ID.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $model
     * @param int $modelId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForModel($query, string $model, int $modelId)
    {
        return $query->where('model', $model)->where('model_id', $modelId);
    }

    /**
     * Get audit logs for a user with caching.
     *
     * @param int $userId
     * @param array $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getAuditLogsForUser(int $userId, array $filters = [])
    {
        $cacheKey = static::getCacheKey('user_audits', $userId, $filters);

        return Cache::tags(['audit_logs', "user:{$userId}"])->remember(
            $cacheKey,
            self::CACHE_TTL['user'],
            function () use ($userId, $filters) {
                $query = static::byUser($userId);

                if (isset($filters['action'])) {
                    $query->byAction($filters['action']);
                }

                if (isset($filters['model'])) {
                    $query->byModel($filters['model']);
                }

                if (isset($filters['recent_hours'])) {
                    $query->recent($filters['recent_hours']);
                }

                if (isset($filters['date_from'])) {
                    $query->createdBetween($filters['date_from'], $filters['date_to'] ?? now());
                }

                return $query->latestFirst()->get();
            }
        );
    }

    /**
     * Get audit logs for a model with caching.
     *
     * @param string $model
     * @param int $modelId
     * @param array $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getAuditLogsForModel(string $model, int $modelId, array $filters = [])
    {
        $cacheKey = static::getCacheKey('model_audits', $model, $modelId, $filters);

        return Cache::tags(['audit_logs', "model:{$model}:{$modelId}"])->remember(
            $cacheKey,
            self::CACHE_TTL['model'],
            function () use ($model, $modelId, $filters) {
                $query = static::forModel($model, $modelId);

                if (isset($filters['action'])) {
                    $query->byAction($filters['action']);
                }

                if (isset($filters['user_id'])) {
                    $query->byUser($filters['user_id']);
                }

                if (isset($filters['recent_hours'])) {
                    $query->recent($filters['recent_hours']);
                }

                return $query->latestFirst()->get();
            }
        );
    }

    /**
     * Get audit logs by action with caching.
     *
     * @param string $action
     * @param array $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getAuditLogsByAction(string $action, array $filters = [])
    {
        $cacheKey = static::getCacheKey('action_audits', $action, $filters);

        return Cache::tags(['audit_logs'])->remember(
            $cacheKey,
            self::CACHE_TTL['audit'],
            function () use ($action, $filters) {
                $query = static::byAction($action);

                if (isset($filters['model'])) {
                    $query->byModel($filters['model']);
                }

                if (isset($filters['user_id'])) {
                    $query->byUser($filters['user_id']);
                }

                if (isset($filters['recent_hours'])) {
                    $query->recent($filters['recent_hours']);
                }

                return $query->latestFirst()->get();
            }
        );
    }

    /**
     * Get audit statistics with caching.
     *
     * @param array $filters
     * @return array
     */
    public static function getAuditStatistics(array $filters = [])
    {
        $cacheKey = static::getCacheKey('statistics', $filters);

        return Cache::tags(['audit_logs'])->remember(
            $cacheKey,
            self::CACHE_TTL['statistics'],
            function () use ($filters) {
                $query = static::query();

                if (isset($filters['date_from'])) {
                    $query->createdBetween($filters['date_from'], $filters['date_to'] ?? now());
                }

                if (isset($filters['user_id'])) {
                    $query->byUser($filters['user_id']);
                }

                if (isset($filters['model'])) {
                    $query->byModel($filters['model']);
                }

                $audits = $query->get();

                return [
                    'total_audits' => $audits->count(),
                    'create_count' => $audits->where('action', self::ACTION_CREATE)->count(),
                    'read_count' => $audits->where('action', self::ACTION_READ)->count(),
                    'update_count' => $audits->where('action', self::ACTION_UPDATE)->count(),
                    'delete_count' => $audits->where('action', self::ACTION_DELETE)->count(),
                    'export_count' => $audits->where('action', self::ACTION_EXPORT)->count(),
                    'login_count' => $audits->where('action', self::ACTION_LOGIN)->count(),
                    'logout_count' => $audits->where('action', self::ACTION_LOGOUT)->count(),
                    'access_count' => $audits->where('action', self::ACTION_ACCESS)->count(),
                    'action_distribution' => $audits->groupBy('action')->map->count(),
                    'model_distribution' => $audits->groupBy('model')->map->count(),
                    'user_distribution' => $audits->groupBy('user_id')->map->count(),
                ];
            }
        );
    }

    /**
     * Get audit trail for a specific model with caching.
     *
     * @param string $model
     * @param int $modelId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getAuditTrail(string $model, int $modelId)
    {
        $cacheKey = static::getCacheKey('audit_trail', $model, $modelId);

        return Cache::tags(['audit_logs', "model:{$model}:{$modelId}"])->remember(
            $cacheKey,
            self::CACHE_TTL['audit'],
            function () use ($model, $modelId) {
                return static::forModel($model, $modelId)
                    ->latestFirst()
                    ->get();
            }
        );
    }

    /**
     * Check if audit log has changes.
     *
     * @return bool
     */
    public function hasAuditChanges(): bool
    {
        return !empty($this->changes);
    }

    /**
     * Check if audit log is a create action.
     *
     * @return bool
     */
    public function isCreate(): bool
    {
        return $this->action === self::ACTION_CREATE;
    }

    /**
     * Check if audit log is a read action.
     *
     * @return bool
     *
     * @return bool
     */
    public function isRead(): bool
    {
        return $this->action === self::ACTION_READ;
    }

    /**
     * Check if audit log is an update action.
     *
     * @return bool
     */
    public function isUpdate(): bool
    {
        return $this->action === self::ACTION_UPDATE;
    }

    /**
     * Check if audit log is a delete action.
     *
     * @return bool
     */
    public function isDelete(): bool
    {
        return $this->action === self::ACTION_DELETE;
    }

    /**
     * Check if audit log is an export action.
     *
     * @return bool
     */
    public function isExport(): bool
    {
        return $this->action === self::ACTION_EXPORT;
    }

    /**
     * Check if audit log is a login action.
     *
     * @return bool
     */
    public function isLogin(): bool
    {
        return $this->action === self::ACTION_LOGIN;
    }

    /**
     * Check if audit log is a logout action.
     *
     * @return bool
     */
    public function isLogout(): bool
    {
        return $this->action === self::ACTION_LOGOUT;
    }

    /**
     * Check if audit log is an access action.
     *
     * @return bool
     */
    public function isAccess(): bool
    {
        return $this->action === self::ACTION_ACCESS;
    }

    /**
     * Get action description.
     *
     * @return string
     */
    public function getActionDescription(): string
    {
        return ucfirst(str_replace('_', ' ', $this->action));
    }

    /**
     * Get model description.
     *
     * @return string
     */
    public function getModelDescription(): string
    {
        return ucfirst(str_replace('_', ' ', $this->model));
    }

    /**
     * Get audit log age in minutes.
     *
     * @return int
     */
    public function getAgeMinutes(): int
    {
        return $this->created_at->diffInMinutes(now());
    }

    /**
     * Get audit log age in hours.
     *
     * @return int
     */
    public function getAgeHours(): int
    {
        return $this->created_at->diffInHours(now());
    }

    /**
     * Get audit log age in days.
     *
     * @return int
     */
    public function getAgeDays(): int
    {
        return $this->created_at->diffInDays(now());
    }

    /**
     * Get audit log summary.
     *
     * @return array
     */
    public function getSummary(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'action' => $this->action,
            'action_description' => $this->getActionDescription(),
            'model' => $this->model,
            'model_description' => $this->getModelDescription(),
            'model_id' => $this->model_id,
            'has_changes' => $this->hasAuditChanges(),
            'changes_count' => $this->hasAuditChanges() ? count($this->changes ?? []) : 0,
            'is_create' => $this->isCreate(),
            'is_read' => $this->isRead(),
            'is_update' => $this->isUpdate(),
            'is_delete' => $this->isDelete(),
            'is_export' => $this->isExport(),
            'is_login' => $this->isLogin(),
            'is_logout' => $this->isLogout(),
            'is_access' => $this->isAccess(),
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'age_minutes' => $this->getAgeMinutes(),
            'age_hours' => $this->getAgeHours(),
            'age_days' => $this->getAgeDays(),
            'created_at' => $this->created_at,
        ];
    }

    /**
     * Log audit event using PostgreSQL function.
     *
     * @param int|null $userId
     * @param string $action
     * @param string $model
     * @param int|null $modelId
     * @param array|null $changes
     * @param string $ipAddress
     * @param string $userAgent
     * @return int
     */
    public static function logEvent(
        ?int $userId,
        string $action,
        string $model,
        ?int $modelId = null,
        ?array $changes = null,
        string $ipAddress = '',
        string $userAgent = ''
    ): int {
        $result = DB::selectOne(
            'SELECT log_audit_event(?, ?, ?, ?, ?::jsonb, ?, ?) as id',
            [
                $userId,
                $action,
                $model,
                $modelId,
                json_encode($changes),
                $ipAddress ?: request()->ip(),
                $userAgent ?: request()->userAgent()
            ]
        );

        // Clear related caches
        static::clearAuditCaches($userId, $model, $modelId);

        return $result->id;
    }

    /**
     * Create or update audit log with caching invalidation.
     *
     * @param array $attributes
     * @return AuditLog
     */
    public static function createOrUpdateAudit(array $attributes): AuditLog
    {
        $audit = static::create($attributes);

        // Clear related caches
        $audit->clearRelatedCaches();

        return $audit;
    }

    /**
     * Clear all caches related to this audit log.
     *
     * @return void
     */
    public function clearRelatedCaches(): void
    {
        // Clear audit caches
        Cache::tags(['audit_logs'])->flush();

        if ($this->user_id) {
            Cache::tags(['audit_logs', "user:{$this->user_id}"])->flush();
        }

        if ($this->model && $this->model_id) {
            Cache::tags(['audit_logs', "model:{$this->model}:{$this->model_id}"])->flush();
        }
    }

    /**
     * Clear audit caches for specific parameters.
     *
     * @param int|null $userId
     * @param string|null $model
     * @param int|null $modelId
     * @return void
     */
    public static function clearAuditCaches(?int $userId = null, ?string $model = null, ?int $modelId = null): void
    {
        Cache::tags(['audit_logs'])->flush();

        if ($userId) {
            Cache::tags(['audit_logs', "user:{$userId}"])->flush();
        }

        if ($model && $modelId) {
            Cache::tags(['audit_logs', "model:{$model}:{$modelId}"])->flush();
        }
    }

    /**
     * Clear all audit log caches.
     *
     * @return void
     */
    public static function clearAllCaches(): void
    {
        Cache::tags(['audit_logs'])->flush();
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
     * Get all valid actions.
     *
     * @return array
     */
    public static function getValidActions(): array
    {
        return [
            self::ACTION_CREATE,
            self::ACTION_READ,
            self::ACTION_UPDATE,
            self::ACTION_DELETE,
            self::ACTION_EXPORT,
            self::ACTION_LOGIN,
            self::ACTION_LOGOUT,
            self::ACTION_ACCESS,
        ];
    }

    /**
     * Get all valid models.
     *
     * @return array
     */
    public static function getValidModels(): array
    {
        return [
            self::MODEL_CONTACT,
            self::MODEL_LEAD,
            self::MODEL_CONVERSATION,
            self::MODEL_ACTIVITY,
            self::MODEL_CONSENT,
            self::MODEL_USER,
        ];
    }

    /**
     * Get action descriptions.
     *
     * @return array
     */
    public static function getActionDescriptions(): array
    {
        return [
            self::ACTION_CREATE => 'Create',
            self::ACTION_READ => 'Read',
            self::ACTION_UPDATE => 'Update',
            self::ACTION_DELETE => 'Delete',
            self::ACTION_EXPORT => 'Export',
            self::ACTION_LOGIN => 'Login',
            self::ACTION_LOGOUT => 'Logout',
            self::ACTION_ACCESS => 'Access',
        ];
    }

    /**
     * Get model descriptions.
     *
     * @return array
     */
    public static function getModelDescriptions(): array
    {
        return [
            self::MODEL_CONTACT => 'Contact',
            self::MODEL_LEAD => 'Lead',
            self::MODEL_CONVERSATION => 'Conversation',
            self::MODEL_ACTIVITY => 'Activity',
            self::MODEL_CONSENT => 'Consent Record',
            self::MODEL_USER => 'User',
        ];
    }
}
