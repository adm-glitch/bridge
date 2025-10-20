<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

/**
 * User Model Test
 * 
 * Tests the User model functionality including
 * relationships, scopes, caching, validation, and user management.
 */
class UserTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test model can be created with valid data.
     *
     * @return void
     */
    public function test_can_create_user()
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'phone' => '+1234567890',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
            'timezone' => 'UTC',
            'language' => 'en',
            'preferences' => ['theme' => 'dark'],
            'permissions' => ['read', 'write'],
        ]);

        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals('John Doe', $user->name);
        $this->assertEquals('john@example.com', $user->email);
        $this->assertEquals(User::ROLE_USER, $user->role);
        $this->assertEquals(User::STATUS_ACTIVE, $user->status);
    }

    /**
     * Test model fillable attributes.
     *
     * @return void
     */
    public function test_fillable_attributes()
    {
        $user = new User();
        $fillable = $user->getFillable();

        $this->assertContains('name', $fillable);
        $this->assertContains('email', $fillable);
        $this->assertContains('password', $fillable);
        $this->assertContains('phone', $fillable);
        $this->assertContains('role', $fillable);
        $this->assertContains('status', $fillable);
        $this->assertContains('timezone', $fillable);
        $this->assertContains('language', $fillable);
        $this->assertContains('preferences', $fillable);
        $this->assertContains('permissions', $fillable);
    }

    /**
     * Test model casts.
     *
     * @return void
     */
    public function test_model_casts()
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
            'preferences' => ['theme' => 'dark'],
            'permissions' => ['read', 'write'],
        ]);

        $this->assertIsInt($user->id);
        $this->assertIsArray($user->preferences);
        $this->assertIsArray($user->permissions);
        $this->assertInstanceOf(\Carbon\Carbon::class, $user->created_at);
    }

    /**
     * Test role validation.
     *
     * @return void
     */
    public function test_role_validation()
    {
        $this->expectException(\InvalidArgumentException::class);

        User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'role' => 'invalid_role',
            'status' => User::STATUS_ACTIVE,
        ]);
    }

    /**
     * Test status validation.
     *
     * @return void
     */
    public function test_status_validation()
    {
        $this->expectException(\InvalidArgumentException::class);

        User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => 'invalid_status',
        ]);
    }

    /**
     * Test email validation.
     *
     * @return void
     */
    public function test_email_validation()
    {
        $this->expectException(\InvalidArgumentException::class);

        User::create([
            'name' => 'John Doe',
            'email' => 'invalid_email',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
        ]);
    }

    /**
     * Test phone validation.
     *
     * @return void
     */
    public function test_phone_validation()
    {
        $this->expectException(\InvalidArgumentException::class);

        User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'phone' => 'invalid_phone',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
        ]);
    }

    /**
     * Test scope by role.
     *
     * @return void
     */
    public function test_scope_by_role()
    {
        User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => 'password123',
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_ACTIVE,
        ]);

        User::create([
            'name' => 'Agent User',
            'email' => 'agent@example.com',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
        ]);

        $adminUsers = User::byRole(User::ROLE_ADMIN)->get();
        $this->assertCount(1, $adminUsers);
        $this->assertEquals(User::ROLE_ADMIN, $adminUsers->first()->role);
    }

    /**
     * Test scope by status.
     *
     * @return void
     */
    public function test_scope_by_status()
    {
        User::create([
            'name' => 'Active User',
            'email' => 'active@example.com',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
        ]);

        User::create([
            'name' => 'Inactive User',
            'email' => 'inactive@example.com',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_INACTIVE,
        ]);

        $activeUsers = User::byStatus(User::STATUS_ACTIVE)->get();
        $this->assertCount(1, $activeUsers);
        $this->assertEquals(User::STATUS_ACTIVE, $activeUsers->first()->status);
    }

    /**
     * Test scope active users.
     *
     * @return void
     */
    public function test_scope_active()
    {
        User::create([
            'name' => 'Active User',
            'email' => 'active@example.com',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
        ]);

        User::create([
            'name' => 'Inactive User',
            'email' => 'inactive@example.com',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_INACTIVE,
        ]);

        $activeUsers = User::active()->get();
        $this->assertCount(1, $activeUsers);
        $this->assertEquals(User::STATUS_ACTIVE, $activeUsers->first()->status);
    }

    /**
     * Test scope admin users.
     *
     * @return void
     */
    public function test_scope_admins()
    {
        User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => 'password123',
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_ACTIVE,
        ]);

        User::create([
            'name' => 'Agent User',
            'email' => 'agent@example.com',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
        ]);

        $adminUsers = User::admins()->get();
        $this->assertCount(1, $adminUsers);
        $this->assertEquals(User::ROLE_ADMIN, $adminUsers->first()->role);
    }

    /**
     * Test scope with recent activity.
     *
     * @return void
     */
    public function test_scope_with_recent_activity()
    {
        User::create([
            'name' => 'Recent User',
            'email' => 'recent@example.com',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
            'last_login_at' => now()->subDays(3),
        ]);

        User::create([
            'name' => 'Old User',
            'email' => 'old@example.com',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
            'last_login_at' => now()->subDays(10),
        ]);

        $recentUsers = User::withRecentActivity(7)->get();
        $this->assertCount(1, $recentUsers);
        $this->assertEquals('recent@example.com', $recentUsers->first()->email);
    }

    /**
     * Test scope by email domain.
     *
     * @return void
     */
    public function test_scope_by_email_domain()
    {
        User::create([
            'name' => 'Company User',
            'email' => 'user@company.com',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
        ]);

        User::create([
            'name' => 'Personal User',
            'email' => 'user@gmail.com',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
        ]);

        $companyUsers = User::byEmailDomain('company.com')->get();
        $this->assertCount(1, $companyUsers);
        $this->assertEquals('user@company.com', $companyUsers->first()->email);
    }

    /**
     * Test scope by timezone.
     *
     * @return void
     */
    public function test_scope_by_timezone()
    {
        User::create([
            'name' => 'UTC User',
            'email' => 'utc@example.com',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
            'timezone' => 'UTC',
        ]);

        User::create([
            'name' => 'EST User',
            'email' => 'est@example.com',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
            'timezone' => 'America/New_York',
        ]);

        $utcUsers = User::byTimezone('UTC')->get();
        $this->assertCount(1, $utcUsers);
        $this->assertEquals('UTC', $utcUsers->first()->timezone);
    }

    /**
     * Test scope by language.
     *
     * @return void
     */
    public function test_scope_by_language()
    {
        User::create([
            'name' => 'English User',
            'email' => 'en@example.com',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
            'language' => 'en',
        ]);

        User::create([
            'name' => 'Spanish User',
            'email' => 'es@example.com',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
            'language' => 'es',
        ]);

        $englishUsers = User::byLanguage('en')->get();
        $this->assertCount(1, $englishUsers);
        $this->assertEquals('en', $englishUsers->first()->language);
    }

    /**
     * Test is active method.
     *
     * @return void
     */
    public function test_is_active()
    {
        $activeUser = User::create([
            'name' => 'Active User',
            'email' => 'active@example.com',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
        ]);

        $inactiveUser = User::create([
            'name' => 'Inactive User',
            'email' => 'inactive@example.com',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_INACTIVE,
        ]);

        $this->assertTrue($activeUser->isActive());
        $this->assertFalse($inactiveUser->isActive());
    }

    /**
     * Test is admin method.
     *
     * @return void
     */
    public function test_is_admin()
    {
        $adminUser = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => 'password123',
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_ACTIVE,
        ]);

        $agentUser = User::create([
            'name' => 'Agent User',
            'email' => 'agent@example.com',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->assertTrue($adminUser->isAdmin());
        $this->assertFalse($agentUser->isAdmin());
    }

    /**
     * Test is manager method.
     *
     * @return void
     */
    public function test_is_manager()
    {
        $managerUser = User::create([
            'name' => 'Manager User',
            'email' => 'manager@example.com',
            'password' => 'password123',
            'role' => User::ROLE_MANAGER,
            'status' => User::STATUS_ACTIVE,
        ]);

        $agentUser = User::create([
            'name' => 'Agent User',
            'email' => 'agent@example.com',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->assertTrue($managerUser->isManager());
        $this->assertFalse($agentUser->isManager());
    }

    /**
     * Test is user method.
     *
     * @return void
     */
    public function test_is_user()
    {
        $userUser = User::create([
            'name' => 'User User',
            'email' => 'user@example.com',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
        ]);

        $adminUser = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => 'password123',
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->assertTrue($userUser->isUser());
        $this->assertFalse($adminUser->isUser());
    }


    /**
     * Test has permission method.
     *
     * @return void
     */
    public function test_has_permission()
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
            'permissions' => ['read', 'write', 'delete'],
        ]);

        $this->assertTrue($user->hasPermission('read'));
        $this->assertTrue($user->hasPermission('write'));
        $this->assertFalse($user->hasPermission('admin'));
    }

    /**
     * Test has any permission method.
     *
     * @return void
     */
    public function test_has_any_permission()
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
            'permissions' => ['read', 'write'],
        ]);

        $this->assertTrue($user->hasAnyPermission(['read', 'admin']));
        $this->assertTrue($user->hasAnyPermission(['write', 'delete']));
        $this->assertFalse($user->hasAnyPermission(['admin', 'super']));
    }

    /**
     * Test has all permissions method.
     *
     * @return void
     */
    public function test_has_all_permissions()
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
            'permissions' => ['read', 'write', 'delete'],
        ]);

        $this->assertTrue($user->hasAllPermissions(['read', 'write']));
        $this->assertTrue($user->hasAllPermissions(['read', 'write', 'delete']));
        $this->assertFalse($user->hasAllPermissions(['read', 'admin']));
    }

    /**
     * Test get last login age minutes method.
     *
     * @return void
     */
    public function test_get_last_login_age_minutes()
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
            'last_login_at' => now()->subMinutes(30),
        ]);

        $age = $user->getLastLoginAgeMinutes();
        $this->assertEquals(30, $age);
    }

    /**
     * Test get last login age hours method.
     *
     * @return void
     */
    public function test_get_last_login_age_hours()
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
            'last_login_at' => now()->subHours(5),
        ]);

        $age = $user->getLastLoginAgeHours();
        $this->assertEquals(5, $age);
    }

    /**
     * Test get last login age days method.
     *
     * @return void
     */
    public function test_get_last_login_age_days()
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
            'last_login_at' => now()->subDays(3),
        ]);

        $age = $user->getLastLoginAgeDays();
        $this->assertEquals(3, $age);
    }

    /**
     * Test has logged in recently method.
     *
     * @return void
     */
    public function test_has_logged_in_recently()
    {
        $recentUser = User::create([
            'name' => 'Recent User',
            'email' => 'recent@example.com',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
            'last_login_at' => now()->subHours(5),
        ]);

        $oldUser = User::create([
            'name' => 'Old User',
            'email' => 'old@example.com',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
            'last_login_at' => now()->subDays(2),
        ]);

        $this->assertTrue($recentUser->hasLoggedInRecently(24));
        $this->assertFalse($oldUser->hasLoggedInRecently(24));
    }

    /**
     * Test get role description method.
     *
     * @return void
     */
    public function test_get_role_description()
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->assertEquals('Agent', $user->getRoleDescription());
    }

    /**
     * Test get status description method.
     *
     * @return void
     */
    public function test_get_status_description()
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->assertEquals('Active', $user->getStatusDescription());
    }

    /**
     * Test get summary method.
     *
     * @return void
     */
    public function test_get_summary()
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
            'last_login_at' => now()->subHours(5),
            'permissions' => ['read', 'write'],
        ]);

        $summary = $user->getSummary();

        $this->assertIsArray($summary);
        $this->assertEquals('Test User', $summary['name']);
        $this->assertEquals('test@example.com', $summary['email']);
        $this->assertEquals(User::ROLE_USER, $summary['role']);
        $this->assertEquals('User', $summary['role_description']);
        $this->assertEquals(User::STATUS_ACTIVE, $summary['status']);
        $this->assertEquals('Active', $summary['status_description']);
        $this->assertTrue($summary['is_active']);
        $this->assertFalse($summary['is_inactive']);
        $this->assertFalse($summary['is_suspended']);
        $this->assertFalse($summary['is_pending']);
        $this->assertFalse($summary['is_admin']);
        $this->assertFalse($summary['is_manager']);
        $this->assertTrue($summary['is_user']);
        $this->assertEquals(5, $summary['last_login_age_hours']);
        $this->assertTrue($summary['has_logged_in_recently']);
        $this->assertEquals(['read', 'write'], $summary['permissions']);
        $this->assertEquals(2, $summary['permissions_count']);
    }

    /**
     * Test update last login method.
     *
     * @return void
     */
    public function test_update_last_login()
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
        ]);

        $result = $user->updateLastLogin('192.168.1.1');

        $this->assertTrue($result);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertEquals('192.168.1.1', $user->fresh()->last_login_ip);
    }

    /**
     * Test create or update user method.
     *
     * @return void
     */
    public function test_create_or_update_user()
    {
        // Test creation
        $user = User::createOrUpdateUser([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals('test@example.com', $user->email);

        // Test update
        $updatedUser = User::createOrUpdateUser([
            'name' => 'Updated User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'role' => User::ROLE_MANAGER,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->assertEquals('Updated User', $updatedUser->name);
        $this->assertEquals(User::ROLE_MANAGER, $updatedUser->role);
        $this->assertEquals($user->id, $updatedUser->id);
    }

    /**
     * Test get valid roles method.
     *
     * @return void
     */
    public function test_get_valid_roles()
    {
        $roles = User::getValidRoles();

        $this->assertContains(User::ROLE_ADMIN, $roles);
        $this->assertContains(User::ROLE_MANAGER, $roles);
        $this->assertContains(User::ROLE_USER, $roles);
    }

    /**
     * Test get valid statuses method.
     *
     * @return void
     */
    public function test_get_valid_statuses()
    {
        $statuses = User::getValidStatuses();

        $this->assertContains(User::STATUS_ACTIVE, $statuses);
        $this->assertContains(User::STATUS_INACTIVE, $statuses);
        $this->assertContains(User::STATUS_SUSPENDED, $statuses);
        $this->assertContains(User::STATUS_PENDING, $statuses);
    }

    /**
     * Test get role descriptions method.
     *
     * @return void
     */
    public function test_get_role_descriptions()
    {
        $descriptions = User::getRoleDescriptions();

        $this->assertEquals('Administrator', $descriptions[User::ROLE_ADMIN]);
        $this->assertEquals('Manager', $descriptions[User::ROLE_MANAGER]);
        $this->assertEquals('User', $descriptions[User::ROLE_USER]);
    }

    /**
     * Test get status descriptions method.
     *
     * @return void
     */
    public function test_get_status_descriptions()
    {
        $descriptions = User::getStatusDescriptions();

        $this->assertEquals('Active', $descriptions[User::STATUS_ACTIVE]);
        $this->assertEquals('Inactive', $descriptions[User::STATUS_INACTIVE]);
        $this->assertEquals('Suspended', $descriptions[User::STATUS_SUSPENDED]);
        $this->assertEquals('Pending', $descriptions[User::STATUS_PENDING]);
    }
}
