<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\ConversationMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

/**
 * ConversationMapping Model Test
 * 
 * Tests the ConversationMapping model functionality including
 * relationships, scopes, caching, validation, and status management.
 */
class ConversationMappingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test model can be created with valid data.
     *
     * @return void
     */
    public function test_can_create_conversation_mapping()
    {
        $mapping = ConversationMapping::create([
            'chatwoot_conversation_id' => 123,
            'krayin_lead_id' => 456,
            'status' => ConversationMapping::STATUS_OPEN,
            'message_count' => 5,
        ]);

        $this->assertInstanceOf(ConversationMapping::class, $mapping);
        $this->assertEquals(123, $mapping->chatwoot_conversation_id);
        $this->assertEquals(456, $mapping->krayin_lead_id);
        $this->assertEquals(ConversationMapping::STATUS_OPEN, $mapping->status);
        $this->assertEquals(5, $mapping->message_count);
    }

    /**
     * Test model fillable attributes.
     *
     * @return void
     */
    public function test_fillable_attributes()
    {
        $mapping = new ConversationMapping();
        $fillable = $mapping->getFillable();

        $this->assertContains('chatwoot_conversation_id', $fillable);
        $this->assertContains('krayin_lead_id', $fillable);
        $this->assertContains('status', $fillable);
        $this->assertContains('message_count', $fillable);
        $this->assertContains('last_message_at', $fillable);
        $this->assertContains('first_response_at', $fillable);
        $this->assertContains('resolved_at', $fillable);
    }

    /**
     * Test model casts.
     *
     * @return void
     */
    public function test_model_casts()
    {
        $mapping = ConversationMapping::create([
            'chatwoot_conversation_id' => '123',
            'krayin_lead_id' => '456',
            'status' => ConversationMapping::STATUS_OPEN,
            'message_count' => '10',
        ]);

        $this->assertIsInt($mapping->chatwoot_conversation_id);
        $this->assertIsInt($mapping->krayin_lead_id);
        $this->assertIsInt($mapping->message_count);
        $this->assertInstanceOf(\Carbon\Carbon::class, $mapping->created_at);
    }

    /**
     * Test status validation.
     *
     * @return void
     */
    public function test_status_validation()
    {
        $this->expectException(\InvalidArgumentException::class);

        ConversationMapping::create([
            'chatwoot_conversation_id' => 123,
            'krayin_lead_id' => 456,
            'status' => 'invalid_status',
        ]);
    }

    /**
     * Test scope by status.
     *
     * @return void
     */
    public function test_scope_by_status()
    {
        ConversationMapping::create([
            'chatwoot_conversation_id' => 123,
            'krayin_lead_id' => 456,
            'status' => ConversationMapping::STATUS_OPEN,
        ]);

        ConversationMapping::create([
            'chatwoot_conversation_id' => 124,
            'krayin_lead_id' => 456,
            'status' => ConversationMapping::STATUS_RESOLVED,
        ]);

        $openConversations = ConversationMapping::status(ConversationMapping::STATUS_OPEN)->get();
        $this->assertCount(1, $openConversations);
        $this->assertEquals(ConversationMapping::STATUS_OPEN, $openConversations->first()->status);
    }

    /**
     * Test scope open conversations.
     *
     * @return void
     */
    public function test_scope_open()
    {
        ConversationMapping::create([
            'chatwoot_conversation_id' => 123,
            'krayin_lead_id' => 456,
            'status' => ConversationMapping::STATUS_OPEN,
        ]);

        ConversationMapping::create([
            'chatwoot_conversation_id' => 124,
            'krayin_lead_id' => 456,
            'status' => ConversationMapping::STATUS_RESOLVED,
        ]);

        $openConversations = ConversationMapping::open()->get();
        $this->assertCount(1, $openConversations);
        $this->assertEquals(ConversationMapping::STATUS_OPEN, $openConversations->first()->status);
    }

    /**
     * Test scope for lead.
     *
     * @return void
     */
    public function test_scope_for_lead()
    {
        ConversationMapping::create([
            'chatwoot_conversation_id' => 123,
            'krayin_lead_id' => 456,
            'status' => ConversationMapping::STATUS_OPEN,
        ]);

        ConversationMapping::create([
            'chatwoot_conversation_id' => 124,
            'krayin_lead_id' => 789,
            'status' => ConversationMapping::STATUS_OPEN,
        ]);

        $leadConversations = ConversationMapping::forLead(456)->get();
        $this->assertCount(1, $leadConversations);
        $this->assertEquals(456, $leadConversations->first()->krayin_lead_id);
    }

    /**
     * Test scope by chatwoot conversation.
     *
     * @return void
     */
    public function test_scope_by_chatwoot_conversation()
    {
        ConversationMapping::create([
            'chatwoot_conversation_id' => 123,
            'krayin_lead_id' => 456,
            'status' => ConversationMapping::STATUS_OPEN,
        ]);

        $conversation = ConversationMapping::byChatwootConversation(123)->first();
        $this->assertNotNull($conversation);
        $this->assertEquals(123, $conversation->chatwoot_conversation_id);
    }

    /**
     * Test scope with minimum messages.
     *
     * @return void
     */
    public function test_scope_with_min_messages()
    {
        ConversationMapping::create([
            'chatwoot_conversation_id' => 123,
            'krayin_lead_id' => 456,
            'status' => ConversationMapping::STATUS_OPEN,
            'message_count' => 5,
        ]);

        ConversationMapping::create([
            'chatwoot_conversation_id' => 124,
            'krayin_lead_id' => 456,
            'status' => ConversationMapping::STATUS_OPEN,
            'message_count' => 15,
        ]);

        $conversationsWithMinMessages = ConversationMapping::withMinMessages(10)->get();
        $this->assertCount(1, $conversationsWithMinMessages);
        $this->assertEquals(15, $conversationsWithMinMessages->first()->message_count);
    }

    /**
     * Test is active method.
     *
     * @return void
     */
    public function test_is_active()
    {
        $openMapping = ConversationMapping::create([
            'chatwoot_conversation_id' => 123,
            'krayin_lead_id' => 456,
            'status' => ConversationMapping::STATUS_OPEN,
        ]);

        $resolvedMapping = ConversationMapping::create([
            'chatwoot_conversation_id' => 124,
            'krayin_lead_id' => 456,
            'status' => ConversationMapping::STATUS_RESOLVED,
        ]);

        $this->assertTrue($openMapping->isActive());
        $this->assertFalse($resolvedMapping->isActive());
    }

    /**
     * Test is resolved method.
     *
     * @return void
     */
    public function test_is_resolved()
    {
        $openMapping = ConversationMapping::create([
            'chatwoot_conversation_id' => 123,
            'krayin_lead_id' => 456,
            'status' => ConversationMapping::STATUS_OPEN,
        ]);

        $resolvedMapping = ConversationMapping::create([
            'chatwoot_conversation_id' => 124,
            'krayin_lead_id' => 456,
            'status' => ConversationMapping::STATUS_RESOLVED,
        ]);

        $this->assertFalse($openMapping->isResolved());
        $this->assertTrue($resolvedMapping->isResolved());
    }

    /**
     * Test has recent activity method.
     *
     * @return void
     */
    public function test_has_recent_activity()
    {
        $recentMapping = ConversationMapping::create([
            'chatwoot_conversation_id' => 123,
            'krayin_lead_id' => 456,
            'status' => ConversationMapping::STATUS_OPEN,
            'last_message_at' => now()->subHours(2),
        ]);

        $oldMapping = ConversationMapping::create([
            'chatwoot_conversation_id' => 124,
            'krayin_lead_id' => 456,
            'status' => ConversationMapping::STATUS_OPEN,
            'last_message_at' => now()->subDays(2),
        ]);

        $this->assertTrue($recentMapping->hasRecentActivity(24));
        $this->assertFalse($oldMapping->hasRecentActivity(24));
    }

    /**
     * Test update status method.
     *
     * @return void
     */
    public function test_update_status()
    {
        $mapping = ConversationMapping::create([
            'chatwoot_conversation_id' => 123,
            'krayin_lead_id' => 456,
            'status' => ConversationMapping::STATUS_OPEN,
        ]);

        $result = $mapping->updateStatus(ConversationMapping::STATUS_RESOLVED);

        $this->assertTrue($result);
        $this->assertEquals(ConversationMapping::STATUS_RESOLVED, $mapping->fresh()->status);
        $this->assertNotNull($mapping->fresh()->resolved_at);
    }

    /**
     * Test mark as resolved method.
     *
     * @return void
     */
    public function test_mark_as_resolved()
    {
        $mapping = ConversationMapping::create([
            'chatwoot_conversation_id' => 123,
            'krayin_lead_id' => 456,
            'status' => ConversationMapping::STATUS_OPEN,
        ]);

        $result = $mapping->markAsResolved();

        $this->assertTrue($result);
        $this->assertEquals(ConversationMapping::STATUS_RESOLVED, $mapping->fresh()->status);
        $this->assertNotNull($mapping->fresh()->resolved_at);
    }

    /**
     * Test mark as open method.
     *
     * @return void
     */
    public function test_mark_as_open()
    {
        $mapping = ConversationMapping::create([
            'chatwoot_conversation_id' => 123,
            'krayin_lead_id' => 456,
            'status' => ConversationMapping::STATUS_RESOLVED,
        ]);

        $result = $mapping->markAsOpen();

        $this->assertTrue($result);
        $this->assertEquals(ConversationMapping::STATUS_OPEN, $mapping->fresh()->status);
    }

    /**
     * Test update message count method.
     *
     * @return void
     */
    public function test_update_message_count()
    {
        $mapping = ConversationMapping::create([
            'chatwoot_conversation_id' => 123,
            'krayin_lead_id' => 456,
            'status' => ConversationMapping::STATUS_OPEN,
            'message_count' => 5,
        ]);

        $timestamp = now();
        $result = $mapping->updateMessageCount(10, $timestamp);

        $this->assertTrue($result);
        $this->assertEquals(10, $mapping->fresh()->message_count);
        $this->assertEquals($timestamp->format('Y-m-d H:i:s'), $mapping->fresh()->last_message_at->format('Y-m-d H:i:s'));
    }

    /**
     * Test set first response method.
     *
     * @return void
     */
    public function test_set_first_response()
    {
        $mapping = ConversationMapping::create([
            'chatwoot_conversation_id' => 123,
            'krayin_lead_id' => 456,
            'status' => ConversationMapping::STATUS_OPEN,
        ]);

        $timestamp = now();
        $result = $mapping->setFirstResponse($timestamp);

        $this->assertTrue($result);
        $this->assertEquals($timestamp->format('Y-m-d H:i:s'), $mapping->fresh()->first_response_at->format('Y-m-d H:i:s'));
    }

    /**
     * Test get duration minutes method.
     *
     * @return void
     */
    public function test_get_duration_minutes()
    {
        $mapping = ConversationMapping::create([
            'chatwoot_conversation_id' => 123,
            'krayin_lead_id' => 456,
            'status' => ConversationMapping::STATUS_RESOLVED,
            'first_response_at' => now()->subHours(2),
            'resolved_at' => now(),
        ]);

        $duration = $mapping->getDurationMinutes();
        $this->assertEquals(120, $duration);
    }

    /**
     * Test get response time minutes method.
     *
     * @return void
     */
    public function test_get_response_time_minutes()
    {
        $mapping = ConversationMapping::create([
            'chatwoot_conversation_id' => 123,
            'krayin_lead_id' => 456,
            'status' => ConversationMapping::STATUS_OPEN,
            'first_response_at' => now()->subHours(1),
        ]);

        // Mock created_at to be 2 hours ago
        $mapping->created_at = now()->subHours(2);
        $mapping->save();

        $responseTime = $mapping->getResponseTimeMinutes();
        $this->assertEquals(60, $responseTime);
    }

    /**
     * Test create or update mapping method.
     *
     * @return void
     */
    public function test_create_or_update_mapping()
    {
        // Test creation
        $mapping = ConversationMapping::createOrUpdateMapping([
            'chatwoot_conversation_id' => 123,
            'krayin_lead_id' => 456,
            'status' => ConversationMapping::STATUS_OPEN,
        ]);

        $this->assertInstanceOf(ConversationMapping::class, $mapping);
        $this->assertEquals(123, $mapping->chatwoot_conversation_id);

        // Test update
        $updatedMapping = ConversationMapping::createOrUpdateMapping([
            'chatwoot_conversation_id' => 123,
            'krayin_lead_id' => 456,
            'status' => ConversationMapping::STATUS_RESOLVED,
        ]);

        $this->assertEquals(ConversationMapping::STATUS_RESOLVED, $updatedMapping->status);
        $this->assertEquals($mapping->id, $updatedMapping->id);
    }

    /**
     * Test get valid statuses method.
     *
     * @return void
     */
    public function test_get_valid_statuses()
    {
        $statuses = ConversationMapping::getValidStatuses();

        $this->assertContains(ConversationMapping::STATUS_OPEN, $statuses);
        $this->assertContains(ConversationMapping::STATUS_RESOLVED, $statuses);
        $this->assertContains(ConversationMapping::STATUS_PENDING, $statuses);
        $this->assertContains(ConversationMapping::STATUS_SNOOZED, $statuses);
    }

    /**
     * Test status display attribute.
     *
     * @return void
     */
    public function test_status_display_attribute()
    {
        $mapping = ConversationMapping::create([
            'chatwoot_conversation_id' => 123,
            'krayin_lead_id' => 456,
            'status' => ConversationMapping::STATUS_OPEN,
        ]);

        $this->assertEquals('Open', $mapping->status_display);
    }
}
