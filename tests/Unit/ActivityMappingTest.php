<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\ActivityMapping;
use App\Models\ConversationMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

/**
 * ActivityMapping Model Test
 * 
 * Tests the ActivityMapping model functionality including
 * relationships, scopes, caching, validation, and message type management.
 */
class ActivityMappingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test model can be created with valid data.
     *
     * @return void
     */
    public function test_can_create_activity_mapping()
    {
        $mapping = ActivityMapping::create([
            'chatwoot_message_id' => 123,
            'krayin_activity_id' => 456,
            'conversation_id' => 789,
            'message_type' => ActivityMapping::TYPE_INCOMING,
        ]);

        $this->assertInstanceOf(ActivityMapping::class, $mapping);
        $this->assertEquals(123, $mapping->chatwoot_message_id);
        $this->assertEquals(456, $mapping->krayin_activity_id);
        $this->assertEquals(789, $mapping->conversation_id);
        $this->assertEquals(ActivityMapping::TYPE_INCOMING, $mapping->message_type);
    }

    /**
     * Test model fillable attributes.
     *
     * @return void
     */
    public function test_fillable_attributes()
    {
        $mapping = new ActivityMapping();
        $fillable = $mapping->getFillable();

        $this->assertContains('chatwoot_message_id', $fillable);
        $this->assertContains('krayin_activity_id', $fillable);
        $this->assertContains('conversation_id', $fillable);
        $this->assertContains('message_type', $fillable);
    }

    /**
     * Test model casts.
     *
     * @return void
     */
    public function test_model_casts()
    {
        $mapping = ActivityMapping::create([
            'chatwoot_message_id' => '123',
            'krayin_activity_id' => '456',
            'conversation_id' => '789',
            'message_type' => ActivityMapping::TYPE_INCOMING,
        ]);

        $this->assertIsInt($mapping->chatwoot_message_id);
        $this->assertIsInt($mapping->krayin_activity_id);
        $this->assertIsInt($mapping->conversation_id);
        $this->assertInstanceOf(\Carbon\Carbon::class, $mapping->created_at);
    }

    /**
     * Test message type validation.
     *
     * @return void
     */
    public function test_message_type_validation()
    {
        $this->expectException(\InvalidArgumentException::class);

        ActivityMapping::create([
            'chatwoot_message_id' => 123,
            'krayin_activity_id' => 456,
            'conversation_id' => 789,
            'message_type' => 'invalid_type',
        ]);
    }

    /**
     * Test scope by message type.
     *
     * @return void
     */
    public function test_scope_by_message_type()
    {
        ActivityMapping::create([
            'chatwoot_message_id' => 123,
            'krayin_activity_id' => 456,
            'conversation_id' => 789,
            'message_type' => ActivityMapping::TYPE_INCOMING,
        ]);

        ActivityMapping::create([
            'chatwoot_message_id' => 124,
            'krayin_activity_id' => 457,
            'conversation_id' => 789,
            'message_type' => ActivityMapping::TYPE_OUTGOING,
        ]);

        $incomingActivities = ActivityMapping::byMessageType(ActivityMapping::TYPE_INCOMING)->get();
        $this->assertCount(1, $incomingActivities);
        $this->assertEquals(ActivityMapping::TYPE_INCOMING, $incomingActivities->first()->message_type);
    }

    /**
     * Test scope incoming messages.
     *
     * @return void
     */
    public function test_scope_incoming()
    {
        ActivityMapping::create([
            'chatwoot_message_id' => 123,
            'krayin_activity_id' => 456,
            'conversation_id' => 789,
            'message_type' => ActivityMapping::TYPE_INCOMING,
        ]);

        ActivityMapping::create([
            'chatwoot_message_id' => 124,
            'krayin_activity_id' => 457,
            'conversation_id' => 789,
            'message_type' => ActivityMapping::TYPE_OUTGOING,
        ]);

        $incomingActivities = ActivityMapping::incoming()->get();
        $this->assertCount(1, $incomingActivities);
        $this->assertEquals(ActivityMapping::TYPE_INCOMING, $incomingActivities->first()->message_type);
    }

    /**
     * Test scope outgoing messages.
     *
     * @return void
     */
    public function test_scope_outgoing()
    {
        ActivityMapping::create([
            'chatwoot_message_id' => 123,
            'krayin_activity_id' => 456,
            'conversation_id' => 789,
            'message_type' => ActivityMapping::TYPE_INCOMING,
        ]);

        ActivityMapping::create([
            'chatwoot_message_id' => 124,
            'krayin_activity_id' => 457,
            'conversation_id' => 789,
            'message_type' => ActivityMapping::TYPE_OUTGOING,
        ]);

        $outgoingActivities = ActivityMapping::outgoing()->get();
        $this->assertCount(1, $outgoingActivities);
        $this->assertEquals(ActivityMapping::TYPE_OUTGOING, $outgoingActivities->first()->message_type);
    }

    /**
     * Test scope activity messages.
     *
     * @return void
     */
    public function test_scope_activity()
    {
        ActivityMapping::create([
            'chatwoot_message_id' => 123,
            'krayin_activity_id' => 456,
            'conversation_id' => 789,
            'message_type' => ActivityMapping::TYPE_ACTIVITY,
        ]);

        ActivityMapping::create([
            'chatwoot_message_id' => 124,
            'krayin_activity_id' => 457,
            'conversation_id' => 789,
            'message_type' => ActivityMapping::TYPE_INCOMING,
        ]);

        $activityMessages = ActivityMapping::activity()->get();
        $this->assertCount(1, $activityMessages);
        $this->assertEquals(ActivityMapping::TYPE_ACTIVITY, $activityMessages->first()->message_type);
    }

    /**
     * Test scope for conversation.
     *
     * @return void
     */
    public function test_scope_for_conversation()
    {
        ActivityMapping::create([
            'chatwoot_message_id' => 123,
            'krayin_activity_id' => 456,
            'conversation_id' => 789,
            'message_type' => ActivityMapping::TYPE_INCOMING,
        ]);

        ActivityMapping::create([
            'chatwoot_message_id' => 124,
            'krayin_activity_id' => 457,
            'conversation_id' => 790,
            'message_type' => ActivityMapping::TYPE_INCOMING,
        ]);

        $conversationActivities = ActivityMapping::forConversation(789)->get();
        $this->assertCount(1, $conversationActivities);
        $this->assertEquals(789, $conversationActivities->first()->conversation_id);
    }

    /**
     * Test scope by chatwoot message.
     *
     * @return void
     */
    public function test_scope_by_chatwoot_message()
    {
        ActivityMapping::create([
            'chatwoot_message_id' => 123,
            'krayin_activity_id' => 456,
            'conversation_id' => 789,
            'message_type' => ActivityMapping::TYPE_INCOMING,
        ]);

        $activity = ActivityMapping::byChatwootMessage(123)->first();
        $this->assertNotNull($activity);
        $this->assertEquals(123, $activity->chatwoot_message_id);
    }

    /**
     * Test scope by krayin activity.
     *
     * @return void
     */
    public function test_scope_by_krayin_activity()
    {
        ActivityMapping::create([
            'chatwoot_message_id' => 123,
            'krayin_activity_id' => 456,
            'conversation_id' => 789,
            'message_type' => ActivityMapping::TYPE_INCOMING,
        ]);

        $activity = ActivityMapping::byKrayinActivity(456)->first();
        $this->assertNotNull($activity);
        $this->assertEquals(456, $activity->krayin_activity_id);
    }

    /**
     * Test scope recent activities.
     *
     * @return void
     */
    public function test_scope_recent()
    {
        ActivityMapping::create([
            'chatwoot_message_id' => 123,
            'krayin_activity_id' => 456,
            'conversation_id' => 789,
            'message_type' => ActivityMapping::TYPE_INCOMING,
            'created_at' => now()->subHours(2),
        ]);

        ActivityMapping::create([
            'chatwoot_message_id' => 124,
            'krayin_activity_id' => 457,
            'conversation_id' => 789,
            'message_type' => ActivityMapping::TYPE_INCOMING,
            'created_at' => now()->subDays(2),
        ]);

        $recentActivities = ActivityMapping::recent(24)->get();
        $this->assertCount(1, $recentActivities);
        $this->assertEquals(123, $recentActivities->first()->chatwoot_message_id);
    }

    /**
     * Test scope latest first.
     *
     * @return void
     */
    public function test_scope_latest_first()
    {
        ActivityMapping::create([
            'chatwoot_message_id' => 123,
            'krayin_activity_id' => 456,
            'conversation_id' => 789,
            'message_type' => ActivityMapping::TYPE_INCOMING,
            'created_at' => now()->subHours(2),
        ]);

        ActivityMapping::create([
            'chatwoot_message_id' => 124,
            'krayin_activity_id' => 457,
            'conversation_id' => 789,
            'message_type' => ActivityMapping::TYPE_INCOMING,
            'created_at' => now()->subHours(1),
        ]);

        $activities = ActivityMapping::latestFirst()->get();
        $this->assertEquals(124, $activities->first()->chatwoot_message_id);
    }

    /**
     * Test scope oldest first.
     *
     * @return void
     */
    public function test_scope_oldest_first()
    {
        ActivityMapping::create([
            'chatwoot_message_id' => 123,
            'krayin_activity_id' => 456,
            'conversation_id' => 789,
            'message_type' => ActivityMapping::TYPE_INCOMING,
            'created_at' => now()->subHours(1),
        ]);

        ActivityMapping::create([
            'chatwoot_message_id' => 124,
            'krayin_activity_id' => 457,
            'conversation_id' => 789,
            'message_type' => ActivityMapping::TYPE_INCOMING,
            'created_at' => now()->subHours(2),
        ]);

        $activities = ActivityMapping::oldestFirst()->get();
        $this->assertEquals(124, $activities->first()->chatwoot_message_id);
    }

    /**
     * Test is incoming method.
     *
     * @return void
     */
    public function test_is_incoming()
    {
        $incomingMapping = ActivityMapping::create([
            'chatwoot_message_id' => 123,
            'krayin_activity_id' => 456,
            'conversation_id' => 789,
            'message_type' => ActivityMapping::TYPE_INCOMING,
        ]);

        $outgoingMapping = ActivityMapping::create([
            'chatwoot_message_id' => 124,
            'krayin_activity_id' => 457,
            'conversation_id' => 789,
            'message_type' => ActivityMapping::TYPE_OUTGOING,
        ]);

        $this->assertTrue($incomingMapping->isIncoming());
        $this->assertFalse($outgoingMapping->isIncoming());
    }

    /**
     * Test is outgoing method.
     *
     * @return void
     */
    public function test_is_outgoing()
    {
        $incomingMapping = ActivityMapping::create([
            'chatwoot_message_id' => 123,
            'krayin_activity_id' => 456,
            'conversation_id' => 789,
            'message_type' => ActivityMapping::TYPE_INCOMING,
        ]);

        $outgoingMapping = ActivityMapping::create([
            'chatwoot_message_id' => 124,
            'krayin_activity_id' => 457,
            'conversation_id' => 789,
            'message_type' => ActivityMapping::TYPE_OUTGOING,
        ]);

        $this->assertFalse($incomingMapping->isOutgoing());
        $this->assertTrue($outgoingMapping->isOutgoing());
    }

    /**
     * Test is activity method.
     *
     * @return void
     */
    public function test_is_activity()
    {
        $activityMapping = ActivityMapping::create([
            'chatwoot_message_id' => 123,
            'krayin_activity_id' => 456,
            'conversation_id' => 789,
            'message_type' => ActivityMapping::TYPE_ACTIVITY,
        ]);

        $incomingMapping = ActivityMapping::create([
            'chatwoot_message_id' => 124,
            'krayin_activity_id' => 457,
            'conversation_id' => 789,
            'message_type' => ActivityMapping::TYPE_INCOMING,
        ]);

        $this->assertTrue($activityMapping->isActivity());
        $this->assertFalse($incomingMapping->isActivity());
    }

    /**
     * Test is recent method.
     *
     * @return void
     */
    public function test_is_recent()
    {
        $recentMapping = ActivityMapping::create([
            'chatwoot_message_id' => 123,
            'krayin_activity_id' => 456,
            'conversation_id' => 789,
            'message_type' => ActivityMapping::TYPE_INCOMING,
            'created_at' => now()->subHours(2),
        ]);

        $oldMapping = ActivityMapping::create([
            'chatwoot_message_id' => 124,
            'krayin_activity_id' => 457,
            'conversation_id' => 789,
            'message_type' => ActivityMapping::TYPE_INCOMING,
            'created_at' => now()->subDays(2),
        ]);

        $this->assertTrue($recentMapping->isRecent(24));
        $this->assertFalse($oldMapping->isRecent(24));
    }

    /**
     * Test get age minutes method.
     *
     * @return void
     */
    public function test_get_age_minutes()
    {
        $mapping = ActivityMapping::create([
            'chatwoot_message_id' => 123,
            'krayin_activity_id' => 456,
            'conversation_id' => 789,
            'message_type' => ActivityMapping::TYPE_INCOMING,
            'created_at' => now()->subMinutes(30),
        ]);

        $age = $mapping->getAgeMinutes();
        $this->assertEquals(30, $age);
    }

    /**
     * Test get age hours method.
     *
     * @return void
     */
    public function test_get_age_hours()
    {
        $mapping = ActivityMapping::create([
            'chatwoot_message_id' => 123,
            'krayin_activity_id' => 456,
            'conversation_id' => 789,
            'message_type' => ActivityMapping::TYPE_INCOMING,
            'created_at' => now()->subHours(5),
        ]);

        $age = $mapping->getAgeHours();
        $this->assertEquals(5, $age);
    }

    /**
     * Test get age days method.
     *
     * @return void
     */
    public function test_get_age_days()
    {
        $mapping = ActivityMapping::create([
            'chatwoot_message_id' => 123,
            'krayin_activity_id' => 456,
            'conversation_id' => 789,
            'message_type' => ActivityMapping::TYPE_INCOMING,
            'created_at' => now()->subDays(3),
        ]);

        $age = $mapping->getAgeDays();
        $this->assertEquals(3, $age);
    }

    /**
     * Test create or update mapping method.
     *
     * @return void
     */
    public function test_create_or_update_mapping()
    {
        // Test creation
        $mapping = ActivityMapping::createOrUpdateMapping([
            'chatwoot_message_id' => 123,
            'krayin_activity_id' => 456,
            'conversation_id' => 789,
            'message_type' => ActivityMapping::TYPE_INCOMING,
        ]);

        $this->assertInstanceOf(ActivityMapping::class, $mapping);
        $this->assertEquals(123, $mapping->chatwoot_message_id);

        // Test update
        $updatedMapping = ActivityMapping::createOrUpdateMapping([
            'chatwoot_message_id' => 123,
            'krayin_activity_id' => 789,
            'conversation_id' => 789,
            'message_type' => ActivityMapping::TYPE_OUTGOING,
        ]);

        $this->assertEquals(ActivityMapping::TYPE_OUTGOING, $updatedMapping->message_type);
        $this->assertEquals($mapping->id, $updatedMapping->id);
    }

    /**
     * Test get valid message types method.
     *
     * @return void
     */
    public function test_get_valid_message_types()
    {
        $types = ActivityMapping::getValidMessageTypes();

        $this->assertContains(ActivityMapping::TYPE_INCOMING, $types);
        $this->assertContains(ActivityMapping::TYPE_OUTGOING, $types);
        $this->assertContains(ActivityMapping::TYPE_ACTIVITY, $types);
    }

    /**
     * Test message type display attribute.
     *
     * @return void
     */
    public function test_message_type_display_attribute()
    {
        $mapping = ActivityMapping::create([
            'chatwoot_message_id' => 123,
            'krayin_activity_id' => 456,
            'conversation_id' => 789,
            'message_type' => ActivityMapping::TYPE_INCOMING,
        ]);

        $this->assertEquals('Incoming', $mapping->message_type_display);
    }

    /**
     * Test age display attribute.
     *
     * @return void
     */
    public function test_age_display_attribute()
    {
        $mapping = ActivityMapping::create([
            'chatwoot_message_id' => 123,
            'krayin_activity_id' => 456,
            'conversation_id' => 789,
            'message_type' => ActivityMapping::TYPE_INCOMING,
            'created_at' => now()->subMinutes(30),
        ]);

        $this->assertStringContainsString('minutes ago', $mapping->age_display);
    }

    /**
     * Test get summary method.
     *
     * @return void
     */
    public function test_get_summary()
    {
        $mapping = ActivityMapping::create([
            'chatwoot_message_id' => 123,
            'krayin_activity_id' => 456,
            'conversation_id' => 789,
            'message_type' => ActivityMapping::TYPE_INCOMING,
        ]);

        $summary = $mapping->getSummary();

        $this->assertIsArray($summary);
        $this->assertEquals(123, $summary['chatwoot_message_id']);
        $this->assertEquals(456, $summary['krayin_activity_id']);
        $this->assertEquals(789, $summary['conversation_id']);
        $this->assertEquals(ActivityMapping::TYPE_INCOMING, $summary['message_type']);
        $this->assertTrue($summary['is_incoming']);
        $this->assertFalse($summary['is_outgoing']);
        $this->assertFalse($summary['is_activity']);
    }
}
