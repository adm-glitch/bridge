<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * User Model
 * 
 * Enhanced user model for healthcare CRM with comprehensive
 * user management, audit trails, and LGPD compliance features.
 * Supports role-based access control and activity tracking.
 * 
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property string|null $phone
 * @property string|null $avatar
 * @property string $role
 * @property string $status
 * @property string|null $last_login_at
 * @property string|null $last_login_ip
 * @property string|null $timezone
 * @property string|null $language
 * @property array|null $preferences
 * @property array|null $permissions
 * @property string|null $notes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * 
 * @property-read AuditLog[] $auditLogs
 * @property-read ContactMapping[] $contactMappings
 * @property-read ConversationMapping[] $conversationMappings
 * @property-read ActivityMapping[] $activityMappings
 * @property-read ConsentRecord[] $consentRecords
 * 
 * @package App\Models
 * @author Bridge Service
 * @version 2.1
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'users';

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
        'name',
        'email',
        'password',
        'phone',
        'avatar',
        'role',
        'status',
        'last_login_at',
        'last_login_ip',
        'timezone',
        'language',
        'preferences',
        'permissions',
        'notes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'id' => 'integer',
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'last_login_at' => 'datetime',
        'preferences' => 'array',
        'permissions' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The connection name for the model.
     * Forces writes to primary database for consistency.
     *
     * @var string
     */
    protected $connection = 'pgsql_write';

    /**
     * Role constants
     */
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MANAGER = 'manager';
    public const ROLE_USER = 'user';

    /**
     * Status constants
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_PENDING = 'pending';

    /**
     * Cache TTL constants (in seconds)
     */
    private const CACHE_TTL = [
        'user' => 300,        // 5 minutes
        'profile' => 180,     // 3 minutes
        'permissions' => 600, // 10 minutes
        'activity' => 300,    // 5 minutes
        'statistics' => 900,  // 15 minutes
    ];

    /**
     * Cache key prefixes
     */
    private const CACHE_PREFIX = 'user';

    /**
     * Boot the model.
     * 
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        // Validate user data
        static::creating(function (User $user) {
            static::validateUser($user);
        });

        static::updating(function (User $user) {
            static::validateUser($user);
        });

        // Clear related caches when model changes
        static::saved(function (User $user) {
            $user->clearRelatedCaches();
        });

        static::deleted(function (User $user) {
            $user->clearRelatedCaches();
        });
    }

    /**
     * Validate user data.
     *
     * @param User $user
     * @return void
     * @throws \InvalidArgumentException
     */
    private static function validateUser(User $user): void
    {
        // Validate role
        $validRoles = [
            self::ROLE_ADMIN,
            self::ROLE_MANAGER,
            self::ROLE_USER,
        ];

        if (!in_array($user->role, $validRoles)) {
            throw new \InvalidArgumentException(
                "Invalid role: {$user->role}. Valid roles are: " . implode(', ', $validRoles)
            );
        }

        // Validate status
        $validStatuses = [
            self::STATUS_ACTIVE,
            self::STATUS_INACTIVE,
            self::STATUS_SUSPENDED,
            self::STATUS_PENDING,
        ];

        if (!in_array($user->status, $validStatuses)) {
            throw new \InvalidArgumentException(
                "Invalid status: {$user->status}. Valid statuses are: " . implode(', ', $validStatuses)
            );
        }

        // Validate email format
        if (!filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid email format: {$user->email}");
        }

        // Validate phone format (if provided)
        if ($user->phone && !preg_match('/^\+?[1-9]\d{1,14}$/', $user->phone)) {
            throw new \InvalidArgumentException("Invalid phone format: {$user->phone}");
        }
    }

    /**
     * Get audit logs for this user.
     * 
     * @return HasMany
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany('App\Models\AuditLog', 'user_id', 'id');
    }

    /**
     * Get contact mappings for this user.
     * 
     * @return HasMany
     */
    public function contactMappings(): HasMany
    {
        return $this->hasMany('App\Models\ContactMapping', 'created_by', 'id');
    }

    /**
     * Get conversation mappings for this user.
     * 
     * @return HasMany
     */
    public function conversationMappings(): HasMany
    {
        return $this->hasMany('App\Models\ConversationMapping', 'created_by', 'id');
    }

    /**
     * Get activity mappings for this user.
     * 
     * @return HasMany
     */
    public function activityMappings(): HasMany
    {
        return $this->hasMany('App\Models\ActivityMapping', 'created_by', 'id');
    }

    /**
     * Get consent records for this user.
     * 
     * @return HasMany
     */
    public function consentRecords(): HasMany
    {
        return $this->hasMany('App\Models\ConsentRecord', 'created_by', 'id');
    }

    /**
     * Scope: Get users by role.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $role
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByRole($query, string $role)
    {
        return $query->where('role', $role);
    }

    /**
     * Scope: Get users by status.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $status
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope: Get active users.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope: Get inactive users.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeInactive($query)
    {
        return $query->where('status', self::STATUS_INACTIVE);
    }

    /**
     * Scope: Get suspended users.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSuspended($query)
    {
        return $query->where('status', self::STATUS_SUSPENDED);
    }

    /**
     * Scope: Get pending users.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope: Get admin users.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAdmins($query)
    {
        return $query->where('role', self::ROLE_ADMIN);
    }

    /**
     * Scope: Get manager users.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeManagers($query)
    {
        return $query->where('role', self::ROLE_MANAGER);
    }

    /**
     * Scope: Get user role users.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeUsers($query)
    {
        return $query->where('role', self::ROLE_USER);
    }

    /**
     * Scope: Get users with recent activity.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $days
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithRecentActivity($query, int $days = 7)
    {
        return $query->where('last_login_at', '>=', now()->subDays($days));
    }

    /**
     * Scope: Get users by email domain.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $domain
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByEmailDomain($query, string $domain)
    {
        return $query->where('email', 'LIKE', "%@{$domain}");
    }

    /**
     * Scope: Get users by timezone.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $timezone
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByTimezone($query, string $timezone)
    {
        return $query->where('timezone', $timezone);
    }

    /**
     * Scope: Get users by language.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $language
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByLanguage($query, string $language)
    {
        return $query->where('language', $language);
    }

    /**
     * Scope: Get users ordered by name.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByName($query)
    {
        return $query->orderBy('name', 'asc');
    }

    /**
     * Scope: Get users ordered by last login.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByLastLogin($query)
    {
        return $query->orderBy('last_login_at', 'desc');
    }

    /**
     * Get user by ID with caching.
     *
     * @param int $userId
     * @return User|null
     */
    public static function getById(int $userId): ?User
    {
        $cacheKey = static::getCacheKey('user', $userId);

        return Cache::tags(['users'])->remember(
            $cacheKey,
            self::CACHE_TTL['user'],
            function () use ($userId) {
                return static::find($userId);
            }
        );
    }

    /**
     * Get user by email with caching.
     *
     * @param string $email
     * @return User|null
     */
    public static function getByEmail(string $email): ?User
    {
        $cacheKey = static::getCacheKey('user_email', $email);

        return Cache::tags(['users'])->remember(
            $cacheKey,
            self::CACHE_TTL['user'],
            function () use ($email) {
                return static::where('email', $email)->first();
            }
        );
    }

    /**
     * Get users by role with caching.
     *
     * @param string $role
     * @param array $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getUsersByRole(string $role, array $filters = [])
    {
        $cacheKey = static::getCacheKey('users_role', $role, $filters);

        return Cache::tags(['users'])->remember(
            $cacheKey,
            self::CACHE_TTL['user'],
            function () use ($role, $filters) {
                $query = static::byRole($role);

                if (isset($filters['status'])) {
                    $query->byStatus($filters['status']);
                }

                if (isset($filters['active_only']) && $filters['active_only']) {
                    $query->active();
                }

                return $query->byName()->get();
            }
        );
    }

    /**
     * Get user profile with caching.
     *
     * @param int $userId
     * @return array
     */
    public static function getUserProfile(int $userId): array
    {
        $cacheKey = static::getCacheKey('user_profile', $userId);

        return Cache::tags(['users', "user:{$userId}"])->remember(
            $cacheKey,
            self::CACHE_TTL['profile'],
            function () use ($userId) {
                $user = static::find($userId);

                if (!$user) {
                    return [];
                }

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'avatar' => $user->avatar,
                    'role' => $user->role,
                    'status' => $user->status,
                    'last_login_at' => $user->last_login_at,
                    'timezone' => $user->timezone,
                    'language' => $user->language,
                    'preferences' => $user->preferences,
                    'permissions' => $user->permissions,
                    'created_at' => $user->created_at,
                ];
            }
        );
    }

    /**
     * Get user activity with caching.
     *
     * @param int $userId
     * @param array $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getUserActivity(int $userId, array $filters = [])
    {
        $cacheKey = static::getCacheKey('user_activity', $userId, $filters);

        return Cache::tags(['users', "user:{$userId}"])->remember(
            $cacheKey,
            self::CACHE_TTL['activity'],
            function () use ($userId, $filters) {
                $query = static::find($userId)->auditLogs();

                if (isset($filters['action'])) {
                    $query->byAction($filters['action']);
                }

                if (isset($filters['model'])) {
                    $query->byModel($filters['model']);
                }

                if (isset($filters['recent_days'])) {
                    $query->recent($filters['recent_days'] * 24);
                }

                return $query->latestFirst()->get();
            }
        );
    }

    /**
     * Get user statistics with caching.
     *
     * @param array $filters
     * @return array
     */
    public static function getUserStatistics(array $filters = [])
    {
        $cacheKey = static::getCacheKey('user_statistics', $filters);

        return Cache::tags(['users'])->remember(
            $cacheKey,
            self::CACHE_TTL['statistics'],
            function () use ($filters) {
                $query = static::query();

                if (isset($filters['role'])) {
                    $query->byRole($filters['role']);
                }

                if (isset($filters['status'])) {
                    $query->byStatus($filters['status']);
                }

                $users = $query->get();

                return [
                    'total_users' => $users->count(),
                    'active_users' => $users->where('status', self::STATUS_ACTIVE)->count(),
                    'inactive_users' => $users->where('status', self::STATUS_INACTIVE)->count(),
                    'suspended_users' => $users->where('status', self::STATUS_SUSPENDED)->count(),
                    'pending_users' => $users->where('status', self::STATUS_PENDING)->count(),
                    'admin_users' => $users->where('role', self::ROLE_ADMIN)->count(),
                    'manager_users' => $users->where('role', self::ROLE_MANAGER)->count(),
                    'user_users' => $users->where('role', self::ROLE_USER)->count(),
                    'role_distribution' => $users->groupBy('role')->map->count(),
                    'status_distribution' => $users->groupBy('status')->map->count(),
                ];
            }
        );
    }

    /**
     * Check if user is active.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Check if user is inactive.
     *
     * @return bool
     */
    public function isInactive(): bool
    {
        return $this->status === self::STATUS_INACTIVE;
    }

    /**
     * Check if user is suspended.
     *
     * @return bool
     */
    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    /**
     * Check if user is pending.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if user is admin.
     *
     * @return bool
     */
    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * Check if user is manager.
     *
     * @return bool
     */
    public function isManager(): bool
    {
        return $this->role === self::ROLE_MANAGER;
    }

    /**
     * Check if user is regular user.
     *
     * @return bool
     */
    public function isUser(): bool
    {
        return $this->role === self::ROLE_USER;
    }

    /**
     * Check if user has permission.
     *
     * @param string $permission
     * @return bool
     */
    public function hasPermission(string $permission): bool
    {
        if (!$this->permissions) {
            return false;
        }

        return in_array($permission, $this->permissions);
    }

    /**
     * Check if user has any of the permissions.
     *
     * @param array $permissions
     * @return bool
     */
    public function hasAnyPermission(array $permissions): bool
    {
        if (!$this->permissions) {
            return false;
        }

        return !empty(array_intersect($permissions, $this->permissions));
    }

    /**
     * Check if user has all permissions.
     *
     * @param array $permissions
     * @return bool
     */
    public function hasAllPermissions(array $permissions): bool
    {
        if (!$this->permissions) {
            return false;
        }

        return empty(array_diff($permissions, $this->permissions));
    }

    /**
     * Get user's last login age in minutes.
     *
     * @return int|null
     */
    public function getLastLoginAgeMinutes(): ?int
    {
        if (!$this->last_login_at) {
            return null;
        }

        /** @var \Carbon\Carbon $lastLogin */
        $lastLogin = $this->last_login_at;
        return $lastLogin->diffInMinutes(now());
    }

    /**
     * Get user's last login age in hours.
     *
     * @return int|null
     */
    public function getLastLoginAgeHours(): ?int
    {
        if (!$this->last_login_at) {
            return null;
        }

        /** @var \Carbon\Carbon $lastLogin */
        $lastLogin = $this->last_login_at;
        return $lastLogin->diffInHours(now());
    }

    /**
     * Get user's last login age in days.
     *
     * @return int|null
     */
    public function getLastLoginAgeDays(): ?int
    {
        if (!$this->last_login_at) {
            return null;
        }

        /** @var \Carbon\Carbon $lastLogin */
        $lastLogin = $this->last_login_at;
        return $lastLogin->diffInDays(now());
    }

    /**
     * Check if user has logged in recently.
     *
     * @param int $hours
     * @return bool
     */
    public function hasLoggedInRecently(int $hours = 24): bool
    {
        if (!$this->last_login_at) {
            return false;
        }

        /** @var \Carbon\Carbon $lastLogin */
        $lastLogin = $this->last_login_at;
        return $lastLogin->isAfter(now()->subHours($hours));
    }

    /**
     * Get role description.
     *
     * @return string
     */
    public function getRoleDescription(): string
    {
        return ucfirst(str_replace('_', ' ', $this->role));
    }

    /**
     * Get status description.
     *
     * @return string
     */
    public function getStatusDescription(): string
    {
        return ucfirst(str_replace('_', ' ', $this->status));
    }

    /**
     * Get user summary.
     *
     * @return array
     */
    public function getSummary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar' => $this->avatar,
            'role' => $this->role,
            'role_description' => $this->getRoleDescription(),
            'status' => $this->status,
            'status_description' => $this->getStatusDescription(),
            'is_active' => $this->isActive(),
            'is_inactive' => $this->isInactive(),
            'is_suspended' => $this->isSuspended(),
            'is_pending' => $this->isPending(),
            'is_admin' => $this->isAdmin(),
            'is_manager' => $this->isManager(),
            'is_user' => $this->isUser(),
            'last_login_at' => $this->last_login_at,
            'last_login_ip' => $this->last_login_ip,
            'last_login_age_minutes' => $this->getLastLoginAgeMinutes(),
            'last_login_age_hours' => $this->getLastLoginAgeHours(),
            'last_login_age_days' => $this->getLastLoginAgeDays(),
            'has_logged_in_recently' => $this->hasLoggedInRecently(),
            'timezone' => $this->timezone,
            'language' => $this->language,
            'preferences' => $this->preferences,
            'permissions' => $this->permissions,
            'permissions_count' => $this->permissions ? count($this->permissions) : 0,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * Update user's last login information.
     *
     * @param string|null $ipAddress
     * @return bool
     */
    public function updateLastLogin(?string $ipAddress = null): bool
    {
        $this->last_login_at = now();
        $this->last_login_ip = $ipAddress ?: request()->ip();

        return $this->save();
    }

    /**
     * Create or update user with caching invalidation.
     *
     * @param array $attributes
     * @return User
     */
    public static function createOrUpdateUser(array $attributes): User
    {
        $user = static::updateOrCreate(
            ['email' => $attributes['email']],
            $attributes
        );

        // Clear related caches
        $user->clearRelatedCaches();

        return $user;
    }

    /**
     * Clear all caches related to this user.
     *
     * @return void
     */
    public function clearRelatedCaches(): void
    {
        // Clear user caches
        Cache::tags(['users'])->forget(
            static::getCacheKey('user', $this->id)
        );

        Cache::tags(['users'])->forget(
            static::getCacheKey('user_email', $this->email)
        );

        Cache::tags(['users', "user:{$this->id}"])->flush();
    }

    /**
     * Clear all user caches.
     *
     * @return void
     */
    public static function clearAllCaches(): void
    {
        Cache::tags(['users'])->flush();
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
     * Get all valid roles.
     *
     * @return array
     */
    public static function getValidRoles(): array
    {
        return [
            self::ROLE_ADMIN,
            self::ROLE_MANAGER,
            self::ROLE_USER,
        ];
    }

    /**
     * Get all valid statuses.
     *
     * @return array
     */
    public static function getValidStatuses(): array
    {
        return [
            self::STATUS_ACTIVE,
            self::STATUS_INACTIVE,
            self::STATUS_SUSPENDED,
            self::STATUS_PENDING,
        ];
    }

    /**
     * Get role descriptions.
     *
     * @return array
     */
    public static function getRoleDescriptions(): array
    {
        return [
            self::ROLE_ADMIN => 'Administrator',
            self::ROLE_MANAGER => 'Manager',
            self::ROLE_USER => 'User',
        ];
    }

    /**
     * Get status descriptions.
     *
     * @return array
     */
    public static function getStatusDescriptions(): array
    {
        return [
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_INACTIVE => 'Inactive',
            self::STATUS_SUSPENDED => 'Suspended',
            self::STATUS_PENDING => 'Pending',
        ];
    }
}
