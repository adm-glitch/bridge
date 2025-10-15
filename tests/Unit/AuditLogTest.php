<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

/**
 * AuditLog Model Test
 * 
 * Tests the AuditLog model functionality including
 * relationships, scopes, caching, validation, and LGPD compliance.
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test model can be created with valid data.
     *
     * @return void
     */
    public function test_can_create_audit_log()
    {
        $audit = AuditLog::create([
            'user_id' => 123,
            'action' => AuditLog::ACTION_CREATE,
            'model' => AuditLog::MODEL_CONTACT,
            'model_id' => 456,
            'changes' => ['name' => 'John Doe'],
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ]);

        $this->assertInstanceOf(AuditLog::class, $audit);
        $this->assertEquals(123, $audit->user_id);
        $this->assertEquals(AuditLog::ACTION_CREATE, $audit->action);
        $this->assertEquals(AuditLog::MODEL_CONTACT, $audit->model);
        $this->assertEquals(456, $audit->model_id);
    }

    /**
     * Test model fillable attributes.
     *
     * @return void
     */
    public function test_fillable_attributes()
    {
        $audit = new AuditLog();
        $fillable = $audit->getFillable();

        $this->assertContains('user_id', $fillable);
        $this->assertContains('action', $fillable);
        $this->assertContains('model', $fillable);
        $this->assertContains('model_id', $fillable);
        $this->assertContains('changes', $fillable);
        $this->assertContains('ip_address', $fillable);
        $this->assertContains('user_agent', $fillable);
    }

    /**
     * Test model casts.
     *
     * @return void
     */
    public function test_model_casts()
    {
        $audit = AuditLog::create([
            'user_id' => '123',
            'action' => AuditLog::ACTION_CREATE,
            'model' => AuditLog::MODEL_CONTACT,
            'model_id' => '456',
            'changes' => ['name' => 'John Doe'],
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        $this->assertIsInt($audit->user_id);
        $this->assertIsInt($audit->model_id);
        $this->assertIsArray($audit->changes);
        $this->assertInstanceOf(\Carbon\Carbon::class, $audit->created_at);
    }

    /**
     * Test action validation.
     *
     * @return void
     */
    public function test_action_validation()
    {
        $this->expectException(\InvalidArgumentException::class);

        AuditLog::create([
            'user_id' => 123,
            'action' => 'invalid_action',
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);
    }

    /**
     * Test model name validation.
     *
     * @return void
     */
    public function test_model_validation()
    {
        $this->expectException(\InvalidArgumentException::class);

        AuditLog::create([
            'user_id' => 123,
            'action' => AuditLog::ACTION_CREATE,
            'model' => '',
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);
    }

    /**
     * Test IP address validation.
     *
     * @return void
     */
    public function test_ip_address_validation()
    {
        $this->expectException(\InvalidArgumentException::class);

        AuditLog::create([
            'user_id' => 123,
            'action' => AuditLog::ACTION_CREATE,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => 'invalid_ip',
            'user_agent' => 'Mozilla/5.0',
        ]);
    }

    /**
     * Test user agent validation.
     *
     * @return void
     */
    public function test_user_agent_validation()
    {
        $this->expectException(\InvalidArgumentException::class);

        AuditLog::create([
            'user_id' => 123,
            'action' => AuditLog::ACTION_CREATE,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.1',
            'user_agent' => '',
        ]);
    }

    /**
     * Test scope by action.
     *
     * @return void
     */
    public function test_scope_by_action()
    {
        AuditLog::create([
            'user_id' => 123,
            'action' => AuditLog::ACTION_CREATE,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        AuditLog::create([
            'user_id' => 124,
            'action' => AuditLog::ACTION_UPDATE,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        $createAudits = AuditLog::byAction(AuditLog::ACTION_CREATE)->get();
        $this->assertCount(1, $createAudits);
        $this->assertEquals(AuditLog::ACTION_CREATE, $createAudits->first()->action);
    }

    /**
     * Test scope by model.
     *
     * @return void
     */
    public function test_scope_by_model()
    {
        AuditLog::create([
            'user_id' => 123,
            'action' => AuditLog::ACTION_CREATE,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        AuditLog::create([
            'user_id' => 124,
            'action' => AuditLog::ACTION_CREATE,
            'model' => AuditLog::MODEL_LEAD,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        $contactAudits = AuditLog::byModel(AuditLog::MODEL_CONTACT)->get();
        $this->assertCount(1, $contactAudits);
        $this->assertEquals(AuditLog::MODEL_CONTACT, $contactAudits->first()->model);
    }

    /**
     * Test scope by user.
     *
     * @return void
     */
    public function test_scope_by_user()
    {
        AuditLog::create([
            'user_id' => 123,
            'action' => AuditLog::ACTION_CREATE,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        AuditLog::create([
            'user_id' => 124,
            'action' => AuditLog::ACTION_CREATE,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        $userAudits = AuditLog::byUser(123)->get();
        $this->assertCount(1, $userAudits);
        $this->assertEquals(123, $userAudits->first()->user_id);
    }

    /**
     * Test scope by model ID.
     *
     * @return void
     */
    public function test_scope_by_model_id()
    {
        AuditLog::create([
            'user_id' => 123,
            'action' => AuditLog::ACTION_CREATE,
            'model' => AuditLog::MODEL_CONTACT,
            'model_id' => 456,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        AuditLog::create([
            'user_id' => 124,
            'action' => AuditLog::ACTION_CREATE,
            'model' => AuditLog::MODEL_CONTACT,
            'model_id' => 789,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        $audits = AuditLog::byModelId(456)->get();
        $this->assertCount(1, $audits);
        $this->assertEquals(456, $audits->first()->model_id);
    }

    /**
     * Test scope by IP address.
     *
     * @return void
     */
    public function test_scope_by_ip_address()
    {
        AuditLog::create([
            'user_id' => 123,
            'action' => AuditLog::ACTION_CREATE,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        AuditLog::create([
            'user_id' => 124,
            'action' => AuditLog::ACTION_CREATE,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.2',
            'user_agent' => 'Mozilla/5.0',
        ]);

        $audits = AuditLog::byIpAddress('192.168.1.1')->get();
        $this->assertCount(1, $audits);
        $this->assertEquals('192.168.1.1', $audits->first()->ip_address);
    }

    /**
     * Test scope created between dates.
     *
     * @return void
     */
    public function test_scope_created_between()
    {
        AuditLog::create([
            'user_id' => 123,
            'action' => AuditLog::ACTION_CREATE,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'created_at' => now()->subDays(5),
        ]);

        AuditLog::create([
            'user_id' => 124,
            'action' => AuditLog::ACTION_CREATE,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'created_at' => now()->subDays(10),
        ]);

        $recentAudits = AuditLog::createdBetween(now()->subDays(7), now())->get();
        $this->assertCount(1, $recentAudits);
    }

    /**
     * Test scope recent.
     *
     * @return void
     */
    public function test_scope_recent()
    {
        AuditLog::create([
            'user_id' => 123,
            'action' => AuditLog::ACTION_CREATE,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'created_at' => now()->subHours(5),
        ]);

        AuditLog::create([
            'user_id' => 124,
            'action' => AuditLog::ACTION_CREATE,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'created_at' => now()->subHours(25),
        ]);

        $recentAudits = AuditLog::recent(24)->get();
        $this->assertCount(1, $recentAudits);
    }

    /**
     * Test scope with changes.
     *
     * @return void
     */
    public function test_scope_with_changes()
    {
        AuditLog::create([
            'user_id' => 123,
            'action' => AuditLog::ACTION_UPDATE,
            'model' => AuditLog::MODEL_CONTACT,
            'changes' => ['name' => 'John Doe'],
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        AuditLog::create([
            'user_id' => 124,
            'action' => AuditLog::ACTION_READ,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        $auditsWithChanges = AuditLog::withChanges()->get();
        $this->assertCount(1, $auditsWithChanges);
        $this->assertNotNull($auditsWithChanges->first()->changes);
    }

    /**
     * Test scope for model.
     *
     * @return void
     */
    public function test_scope_for_model()
    {
        AuditLog::create([
            'user_id' => 123,
            'action' => AuditLog::ACTION_CREATE,
            'model' => AuditLog::MODEL_CONTACT,
            'model_id' => 456,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        AuditLog::create([
            'user_id' => 124,
            'action' => AuditLog::ACTION_CREATE,
            'model' => AuditLog::MODEL_CONTACT,
            'model_id' => 789,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        $audits = AuditLog::forModel(AuditLog::MODEL_CONTACT, 456)->get();
        $this->assertCount(1, $audits);
        $this->assertEquals(AuditLog::MODEL_CONTACT, $audits->first()->model);
        $this->assertEquals(456, $audits->first()->model_id);
    }

    /**
     * Test has audit changes method.
     *
     * @return void
     */
    public function test_has_audit_changes()
    {
        $auditWithChanges = AuditLog::create([
            'user_id' => 123,
            'action' => AuditLog::ACTION_UPDATE,
            'model' => AuditLog::MODEL_CONTACT,
            'changes' => ['name' => 'John Doe'],
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        $auditWithoutChanges = AuditLog::create([
            'user_id' => 124,
            'action' => AuditLog::ACTION_READ,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        $this->assertTrue($auditWithChanges->hasAuditChanges());
        $this->assertFalse($auditWithoutChanges->hasAuditChanges());
    }

    /**
     * Test is create method.
     *
     * @return void
     */
    public function test_is_create()
    {
        $createAudit = AuditLog::create([
            'user_id' => 123,
            'action' => AuditLog::ACTION_CREATE,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        $updateAudit = AuditLog::create([
            'user_id' => 124,
            'action' => AuditLog::ACTION_UPDATE,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        $this->assertTrue($createAudit->isCreate());
        $this->assertFalse($updateAudit->isCreate());
    }

    /**
     * Test is read method.
     *
     * @return void
     */
    public function test_is_read()
    {
        $readAudit = AuditLog::create([
            'user_id' => 123,
            'action' => AuditLog::ACTION_READ,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        $updateAudit = AuditLog::create([
            'user_id' => 124,
            'action' => AuditLog::ACTION_UPDATE,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        $this->assertTrue($readAudit->isRead());
        $this->assertFalse($updateAudit->isRead());
    }

    /**
     * Test is update method.
     *
     * @return void
     */
    public function test_is_update()
    {
        $updateAudit = AuditLog::create([
            'user_id' => 123,
            'action' => AuditLog::ACTION_UPDATE,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        $createAudit = AuditLog::create([
            'user_id' => 124,
            'action' => AuditLog::ACTION_CREATE,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        $this->assertTrue($updateAudit->isUpdate());
        $this->assertFalse($createAudit->isUpdate());
    }

    /**
     * Test is delete method.
     *
     * @return void
     */
    public function test_is_delete()
    {
        $deleteAudit = AuditLog::create([
            'user_id' => 123,
            'action' => AuditLog::ACTION_DELETE,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        $createAudit = AuditLog::create([
            'user_id' => 124,
            'action' => AuditLog::ACTION_CREATE,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        $this->assertTrue($deleteAudit->isDelete());
        $this->assertFalse($createAudit->isDelete());
    }

    /**
     * Test is export method.
     *
     * @return void
     */
    public function test_is_export()
    {
        $exportAudit = AuditLog::create([
            'user_id' => 123,
            'action' => AuditLog::ACTION_EXPORT,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        $createAudit = AuditLog::create([
            'user_id' => 124,
            'action' => AuditLog::ACTION_CREATE,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        $this->assertTrue($exportAudit->isExport());
        $this->assertFalse($createAudit->isExport());
    }

    /**
     * Test is login method.
     *
     * @return void
     */
    public function test_is_login()
    {
        $loginAudit = AuditLog::create([
            'user_id' => 123,
            'action' => AuditLog::ACTION_LOGIN,
            'model' => AuditLog::MODEL_USER,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        $createAudit = AuditLog::create([
            'user_id' => 124,
            'action' => AuditLog::ACTION_CREATE,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        $this->assertTrue($loginAudit->isLogin());
        $this->assertFalse($createAudit->isLogin());
    }

    /**
     * Test is logout method.
     *
     * @return void
     */
    public function test_is_logout()
    {
        $logoutAudit = AuditLog::create([
            'user_id' => 123,
            'action' => AuditLog::ACTION_LOGOUT,
            'model' => AuditLog::MODEL_USER,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        $createAudit = AuditLog::create([
            'user_id' => 124,
            'action' => AuditLog::ACTION_CREATE,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        $this->assertTrue($logoutAudit->isLogout());
        $this->assertFalse($createAudit->isLogout());
    }

    /**
     * Test is access method.
     *
     * @return void
     */
    public function test_is_access()
    {
        $accessAudit = AuditLog::create([
            'user_id' => 123,
            'action' => AuditLog::ACTION_ACCESS,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        $createAudit = AuditLog::create([
            'user_id' => 124,
            'action' => AuditLog::ACTION_CREATE,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        $this->assertTrue($accessAudit->isAccess());
        $this->assertFalse($createAudit->isAccess());
    }

    /**
     * Test get action description method.
     *
     * @return void
     */
    public function test_get_action_description()
    {
        $audit = AuditLog::create([
            'user_id' => 123,
            'action' => AuditLog::ACTION_CREATE,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        $this->assertEquals('Create', $audit->getActionDescription());
    }

    /**
     * Test get model description method.
     *
     * @return void
     */
    public function test_get_model_description()
    {
        $audit = AuditLog::create([
            'user_id' => 123,
            'action' => AuditLog::ACTION_CREATE,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        $this->assertEquals('Contact', $audit->getModelDescription());
    }

    /**
     * Test get age minutes method.
     *
     * @return void
     */
    public function test_get_age_minutes()
    {
        $audit = AuditLog::create([
            'user_id' => 123,
            'action' => AuditLog::ACTION_CREATE,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'created_at' => now()->subMinutes(30),
        ]);

        $age = $audit->getAgeMinutes();
        $this->assertEquals(30, $age);
    }

    /**
     * Test get age hours method.
     *
     * @return void
     */
    public function test_get_age_hours()
    {
        $audit = AuditLog::create([
            'user_id' => 123,
            'action' => AuditLog::ACTION_CREATE,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'created_at' => now()->subHours(5),
        ]);

        $age = $audit->getAgeHours();
        $this->assertEquals(5, $age);
    }

    /**
     * Test get age days method.
     *
     * @return void
     */
    public function test_get_age_days()
    {
        $audit = AuditLog::create([
            'user_id' => 123,
            'action' => AuditLog::ACTION_CREATE,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'created_at' => now()->subDays(3),
        ]);

        $age = $audit->getAgeDays();
        $this->assertEquals(3, $age);
    }

    /**
     * Test get summary method.
     *
     * @return void
     */
    public function test_get_summary()
    {
        $audit = AuditLog::create([
            'user_id' => 123,
            'action' => AuditLog::ACTION_UPDATE,
            'model' => AuditLog::MODEL_CONTACT,
            'model_id' => 456,
            'changes' => ['name' => 'John Doe'],
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'created_at' => now()->subMinutes(30),
        ]);

        $summary = $audit->getSummary();

        $this->assertIsArray($summary);
        $this->assertEquals(123, $summary['user_id']);
        $this->assertEquals(AuditLog::ACTION_UPDATE, $summary['action']);
        $this->assertEquals('Update', $summary['action_description']);
        $this->assertEquals(AuditLog::MODEL_CONTACT, $summary['model']);
        $this->assertEquals('Contact', $summary['model_description']);
        $this->assertEquals(456, $summary['model_id']);
        $this->assertTrue($summary['has_changes']);
        $this->assertEquals(1, $summary['changes_count']);
        $this->assertTrue($summary['is_update']);
        $this->assertFalse($summary['is_create']);
        $this->assertFalse($summary['is_read']);
        $this->assertFalse($summary['is_delete']);
        $this->assertFalse($summary['is_export']);
        $this->assertFalse($summary['is_login']);
        $this->assertFalse($summary['is_logout']);
        $this->assertFalse($summary['is_access']);
        $this->assertEquals('192.168.1.1', $summary['ip_address']);
        $this->assertEquals('Mozilla/5.0', $summary['user_agent']);
        $this->assertEquals(30, $summary['age_minutes']);
    }

    /**
     * Test create or update audit method.
     *
     * @return void
     */
    public function test_create_or_update_audit()
    {
        // Test creation
        $audit = AuditLog::createOrUpdateAudit([
            'user_id' => 123,
            'action' => AuditLog::ACTION_CREATE,
            'model' => AuditLog::MODEL_CONTACT,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        $this->assertInstanceOf(AuditLog::class, $audit);
        $this->assertEquals(123, $audit->user_id);
    }

    /**
     * Test get valid actions method.
     *
     * @return void
     */
    public function test_get_valid_actions()
    {
        $actions = AuditLog::getValidActions();

        $this->assertContains(AuditLog::ACTION_CREATE, $actions);
        $this->assertContains(AuditLog::ACTION_READ, $actions);
        $this->assertContains(AuditLog::ACTION_UPDATE, $actions);
        $this->assertContains(AuditLog::ACTION_DELETE, $actions);
        $this->assertContains(AuditLog::ACTION_EXPORT, $actions);
        $this->assertContains(AuditLog::ACTION_LOGIN, $actions);
        $this->assertContains(AuditLog::ACTION_LOGOUT, $actions);
        $this->assertContains(AuditLog::ACTION_ACCESS, $actions);
    }

    /**
     * Test get valid models method.
     *
     * @return void
     */
    public function test_get_valid_models()
    {
        $models = AuditLog::getValidModels();

        $this->assertContains(AuditLog::MODEL_CONTACT, $models);
        $this->assertContains(AuditLog::MODEL_LEAD, $models);
        $this->assertContains(AuditLog::MODEL_CONVERSATION, $models);
        $this->assertContains(AuditLog::MODEL_ACTIVITY, $models);
        $this->assertContains(AuditLog::MODEL_CONSENT, $models);
        $this->assertContains(AuditLog::MODEL_USER, $models);
    }

    /**
     * Test get action descriptions method.
     *
     * @return void
     */
    public function test_get_action_descriptions()
    {
        $descriptions = AuditLog::getActionDescriptions();

        $this->assertEquals('Create', $descriptions[AuditLog::ACTION_CREATE]);
        $this->assertEquals('Read', $descriptions[AuditLog::ACTION_READ]);
        $this->assertEquals('Update', $descriptions[AuditLog::ACTION_UPDATE]);
        $this->assertEquals('Delete', $descriptions[AuditLog::ACTION_DELETE]);
        $this->assertEquals('Export', $descriptions[AuditLog::ACTION_EXPORT]);
        $this->assertEquals('Login', $descriptions[AuditLog::ACTION_LOGIN]);
        $this->assertEquals('Logout', $descriptions[AuditLog::ACTION_LOGOUT]);
        $this->assertEquals('Access', $descriptions[AuditLog::ACTION_ACCESS]);
    }

    /**
     * Test get model descriptions method.
     *
     * @return void
     */
    public function test_get_model_descriptions()
    {
        $descriptions = AuditLog::getModelDescriptions();

        $this->assertEquals('Contact', $descriptions[AuditLog::MODEL_CONTACT]);
        $this->assertEquals('Lead', $descriptions[AuditLog::MODEL_LEAD]);
        $this->assertEquals('Conversation', $descriptions[AuditLog::MODEL_CONVERSATION]);
        $this->assertEquals('Activity', $descriptions[AuditLog::MODEL_ACTIVITY]);
        $this->assertEquals('Consent Record', $descriptions[AuditLog::MODEL_CONSENT]);
        $this->assertEquals('User', $descriptions[AuditLog::MODEL_USER]);
    }
}
