<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\AiInsight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

/**
 * AiInsight Model Test
 * 
 * Tests the AiInsight model functionality including
 * relationships, scopes, caching, validation, and performance analytics.
 */
class AiInsightTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test model can be created with valid data.
     *
     * @return void
     */
    public function test_can_create_ai_insight()
    {
        $insight = AiInsight::create([
            'krayin_lead_id' => 123,
            'total_conversations' => 10,
            'resolved_conversations' => 8,
            'pending_conversations' => 2,
            'resolution_rate' => 80.0,
            'average_response_time_minutes' => 15,
            'total_messages' => 50,
            'average_messages_per_conversation' => 5.0,
            'performance_score' => 7.5,
            'engagement_level' => AiInsight::ENGAGEMENT_HIGH,
            'trend' => AiInsight::TREND_IMPROVING,
            'suggestions' => ['Follow up with customer', 'Schedule call'],
            'last_interaction_at' => now(),
            'is_current' => true,
        ]);

        $this->assertInstanceOf(AiInsight::class, $insight);
        $this->assertEquals(123, $insight->krayin_lead_id);
        $this->assertEquals(10, $insight->total_conversations);
        $this->assertEquals(7.5, $insight->performance_score);
        $this->assertEquals(AiInsight::ENGAGEMENT_HIGH, $insight->engagement_level);
        $this->assertEquals(AiInsight::TREND_IMPROVING, $insight->trend);
    }

    /**
     * Test model fillable attributes.
     *
     * @return void
     */
    public function test_fillable_attributes()
    {
        $insight = new AiInsight();
        $fillable = $insight->getFillable();

        $this->assertContains('krayin_lead_id', $fillable);
        $this->assertContains('total_conversations', $fillable);
        $this->assertContains('performance_score', $fillable);
        $this->assertContains('engagement_level', $fillable);
        $this->assertContains('trend', $fillable);
        $this->assertContains('suggestions', $fillable);
        $this->assertContains('is_current', $fillable);
    }

    /**
     * Test model casts.
     *
     * @return void
     */
    public function test_model_casts()
    {
        $insight = AiInsight::create([
            'krayin_lead_id' => '123',
            'total_conversations' => '10',
            'performance_score' => '7.5',
            'engagement_level' => AiInsight::ENGAGEMENT_HIGH,
            'suggestions' => ['test'],
            'is_current' => '1',
        ]);

        $this->assertIsInt($insight->krayin_lead_id);
        $this->assertIsInt($insight->total_conversations);
        $this->assertIsFloat($insight->performance_score);
        $this->assertIsArray($insight->suggestions);
        $this->assertIsBool($insight->is_current);
        $this->assertInstanceOf(\Carbon\Carbon::class, $insight->created_at);
    }

    /**
     * Test engagement level validation.
     *
     * @return void
     */
    public function test_engagement_level_validation()
    {
        $this->expectException(\InvalidArgumentException::class);

        AiInsight::create([
            'krayin_lead_id' => 123,
            'engagement_level' => 'invalid_level',
            'performance_score' => 7.5,
        ]);
    }

    /**
     * Test performance score validation.
     *
     * @return void
     */
    public function test_performance_score_validation()
    {
        $this->expectException(\InvalidArgumentException::class);

        AiInsight::create([
            'krayin_lead_id' => 123,
            'engagement_level' => AiInsight::ENGAGEMENT_HIGH,
            'performance_score' => 15.0, // Invalid: > 10
        ]);
    }

    /**
     * Test trend validation.
     *
     * @return void
     */
    public function test_trend_validation()
    {
        $this->expectException(\InvalidArgumentException::class);

        AiInsight::create([
            'krayin_lead_id' => 123,
            'engagement_level' => AiInsight::ENGAGEMENT_HIGH,
            'performance_score' => 7.5,
            'trend' => 'invalid_trend',
        ]);
    }

    /**
     * Test scope current insights.
     *
     * @return void
     */
    public function test_scope_current()
    {
        AiInsight::create([
            'krayin_lead_id' => 123,
            'performance_score' => 7.5,
            'engagement_level' => AiInsight::ENGAGEMENT_HIGH,
            'is_current' => true,
        ]);

        AiInsight::create([
            'krayin_lead_id' => 124,
            'performance_score' => 6.0,
            'engagement_level' => AiInsight::ENGAGEMENT_MEDIUM,
            'is_current' => false,
        ]);

        $currentInsights = AiInsight::current()->get();
        $this->assertCount(1, $currentInsights);
        $this->assertTrue($currentInsights->first()->is_current);
    }

    /**
     * Test scope historical insights.
     *
     * @return void
     */
    public function test_scope_historical()
    {
        AiInsight::create([
            'krayin_lead_id' => 123,
            'performance_score' => 7.5,
            'engagement_level' => AiInsight::ENGAGEMENT_HIGH,
            'is_current' => true,
        ]);

        AiInsight::create([
            'krayin_lead_id' => 124,
            'performance_score' => 6.0,
            'engagement_level' => AiInsight::ENGAGEMENT_MEDIUM,
            'is_current' => false,
        ]);

        $historicalInsights = AiInsight::historical()->get();
        $this->assertCount(1, $historicalInsights);
        $this->assertFalse($historicalInsights->first()->is_current);
    }

    /**
     * Test scope for lead.
     *
     * @return void
     */
    public function test_scope_for_lead()
    {
        AiInsight::create([
            'krayin_lead_id' => 123,
            'performance_score' => 7.5,
            'engagement_level' => AiInsight::ENGAGEMENT_HIGH,
            'is_current' => true,
        ]);

        AiInsight::create([
            'krayin_lead_id' => 124,
            'performance_score' => 6.0,
            'engagement_level' => AiInsight::ENGAGEMENT_MEDIUM,
            'is_current' => true,
        ]);

        $leadInsights = AiInsight::forLead(123)->get();
        $this->assertCount(1, $leadInsights);
        $this->assertEquals(123, $leadInsights->first()->krayin_lead_id);
    }

    /**
     * Test scope by engagement level.
     *
     * @return void
     */
    public function test_scope_by_engagement_level()
    {
        AiInsight::create([
            'krayin_lead_id' => 123,
            'performance_score' => 7.5,
            'engagement_level' => AiInsight::ENGAGEMENT_HIGH,
            'is_current' => true,
        ]);

        AiInsight::create([
            'krayin_lead_id' => 124,
            'performance_score' => 6.0,
            'engagement_level' => AiInsight::ENGAGEMENT_MEDIUM,
            'is_current' => true,
        ]);

        $highEngagementInsights = AiInsight::byEngagementLevel(AiInsight::ENGAGEMENT_HIGH)->get();
        $this->assertCount(1, $highEngagementInsights);
        $this->assertEquals(AiInsight::ENGAGEMENT_HIGH, $highEngagementInsights->first()->engagement_level);
    }

    /**
     * Test scope by trend.
     *
     * @return void
     */
    public function test_scope_by_trend()
    {
        AiInsight::create([
            'krayin_lead_id' => 123,
            'performance_score' => 7.5,
            'engagement_level' => AiInsight::ENGAGEMENT_HIGH,
            'trend' => AiInsight::TREND_IMPROVING,
            'is_current' => true,
        ]);

        AiInsight::create([
            'krayin_lead_id' => 124,
            'performance_score' => 6.0,
            'engagement_level' => AiInsight::ENGAGEMENT_MEDIUM,
            'trend' => AiInsight::TREND_STABLE,
            'is_current' => true,
        ]);

        $improvingInsights = AiInsight::byTrend(AiInsight::TREND_IMPROVING)->get();
        $this->assertCount(1, $improvingInsights);
        $this->assertEquals(AiInsight::TREND_IMPROVING, $improvingInsights->first()->trend);
    }

    /**
     * Test scope with minimum score.
     *
     * @return void
     */
    public function test_scope_with_min_score()
    {
        AiInsight::create([
            'krayin_lead_id' => 123,
            'performance_score' => 8.5,
            'engagement_level' => AiInsight::ENGAGEMENT_HIGH,
            'is_current' => true,
        ]);

        AiInsight::create([
            'krayin_lead_id' => 124,
            'performance_score' => 6.0,
            'engagement_level' => AiInsight::ENGAGEMENT_MEDIUM,
            'is_current' => true,
        ]);

        $highScoreInsights = AiInsight::withMinScore(7.0)->get();
        $this->assertCount(1, $highScoreInsights);
        $this->assertEquals(8.5, $highScoreInsights->first()->performance_score);
    }

    /**
     * Test scope with maximum score.
     *
     * @return void
     */
    public function test_scope_with_max_score()
    {
        AiInsight::create([
            'krayin_lead_id' => 123,
            'performance_score' => 8.5,
            'engagement_level' => AiInsight::ENGAGEMENT_HIGH,
            'is_current' => true,
        ]);

        AiInsight::create([
            'krayin_lead_id' => 124,
            'performance_score' => 6.0,
            'engagement_level' => AiInsight::ENGAGEMENT_MEDIUM,
            'is_current' => true,
        ]);

        $lowScoreInsights = AiInsight::withMaxScore(7.0)->get();
        $this->assertCount(1, $lowScoreInsights);
        $this->assertEquals(6.0, $lowScoreInsights->first()->performance_score);
    }

    /**
     * Test scope with recent activity.
     *
     * @return void
     */
    public function test_scope_with_recent_activity()
    {
        AiInsight::create([
            'krayin_lead_id' => 123,
            'performance_score' => 7.5,
            'engagement_level' => AiInsight::ENGAGEMENT_HIGH,
            'last_interaction_at' => now()->subDays(3),
            'is_current' => true,
        ]);

        AiInsight::create([
            'krayin_lead_id' => 124,
            'performance_score' => 6.0,
            'engagement_level' => AiInsight::ENGAGEMENT_MEDIUM,
            'last_interaction_at' => now()->subDays(10),
            'is_current' => true,
        ]);

        $recentInsights = AiInsight::withRecentActivity(7)->get();
        $this->assertCount(1, $recentInsights);
        $this->assertEquals(123, $recentInsights->first()->krayin_lead_id);
    }

    /**
     * Test scope by performance score.
     *
     * @return void
     */
    public function test_scope_by_performance_score()
    {
        AiInsight::create([
            'krayin_lead_id' => 123,
            'performance_score' => 6.0,
            'engagement_level' => AiInsight::ENGAGEMENT_HIGH,
            'is_current' => true,
        ]);

        AiInsight::create([
            'krayin_lead_id' => 124,
            'performance_score' => 8.5,
            'engagement_level' => AiInsight::ENGAGEMENT_MEDIUM,
            'is_current' => true,
        ]);

        $insights = AiInsight::byPerformanceScore()->get();
        $this->assertEquals(8.5, $insights->first()->performance_score);
        $this->assertEquals(6.0, $insights->last()->performance_score);
    }

    /**
     * Test is high performance method.
     *
     * @return void
     */
    public function test_is_high_performance()
    {
        $highPerformanceInsight = AiInsight::create([
            'krayin_lead_id' => 123,
            'performance_score' => 8.5,
            'engagement_level' => AiInsight::ENGAGEMENT_HIGH,
            'is_current' => true,
        ]);

        $lowPerformanceInsight = AiInsight::create([
            'krayin_lead_id' => 124,
            'performance_score' => 5.0,
            'engagement_level' => AiInsight::ENGAGEMENT_MEDIUM,
            'is_current' => true,
        ]);

        $this->assertTrue($highPerformanceInsight->isHighPerformance());
        $this->assertFalse($lowPerformanceInsight->isHighPerformance());
    }

    /**
     * Test is low performance method.
     *
     * @return void
     */
    public function test_is_low_performance()
    {
        $highPerformanceInsight = AiInsight::create([
            'krayin_lead_id' => 123,
            'performance_score' => 8.5,
            'engagement_level' => AiInsight::ENGAGEMENT_HIGH,
            'is_current' => true,
        ]);

        $lowPerformanceInsight = AiInsight::create([
            'krayin_lead_id' => 124,
            'performance_score' => 4.0,
            'engagement_level' => AiInsight::ENGAGEMENT_MEDIUM,
            'is_current' => true,
        ]);

        $this->assertFalse($highPerformanceInsight->isLowPerformance());
        $this->assertTrue($lowPerformanceInsight->isLowPerformance());
    }

    /**
     * Test is high engagement method.
     *
     * @return void
     */
    public function test_is_high_engagement()
    {
        $highEngagementInsight = AiInsight::create([
            'krayin_lead_id' => 123,
            'performance_score' => 7.5,
            'engagement_level' => AiInsight::ENGAGEMENT_HIGH,
            'is_current' => true,
        ]);

        $lowEngagementInsight = AiInsight::create([
            'krayin_lead_id' => 124,
            'performance_score' => 6.0,
            'engagement_level' => AiInsight::ENGAGEMENT_LOW,
            'is_current' => true,
        ]);

        $this->assertTrue($highEngagementInsight->isHighEngagement());
        $this->assertFalse($lowEngagementInsight->isHighEngagement());
    }

    /**
     * Test is improving method.
     *
     * @return void
     */
    public function test_is_improving()
    {
        $improvingInsight = AiInsight::create([
            'krayin_lead_id' => 123,
            'performance_score' => 7.5,
            'engagement_level' => AiInsight::ENGAGEMENT_HIGH,
            'trend' => AiInsight::TREND_IMPROVING,
            'is_current' => true,
        ]);

        $stableInsight = AiInsight::create([
            'krayin_lead_id' => 124,
            'performance_score' => 6.0,
            'engagement_level' => AiInsight::ENGAGEMENT_MEDIUM,
            'trend' => AiInsight::TREND_STABLE,
            'is_current' => true,
        ]);

        $this->assertTrue($improvingInsight->isImproving());
        $this->assertFalse($stableInsight->isImproving());
    }

    /**
     * Test get performance grade method.
     *
     * @return void
     */
    public function test_get_performance_grade()
    {
        $excellentInsight = AiInsight::create([
            'krayin_lead_id' => 123,
            'performance_score' => 9.5,
            'engagement_level' => AiInsight::ENGAGEMENT_HIGH,
            'is_current' => true,
        ]);

        $goodInsight = AiInsight::create([
            'krayin_lead_id' => 124,
            'performance_score' => 7.5,
            'engagement_level' => AiInsight::ENGAGEMENT_MEDIUM,
            'is_current' => true,
        ]);

        $this->assertEquals('A+', $excellentInsight->getPerformanceGrade());
        $this->assertEquals('A', $goodInsight->getPerformanceGrade());
    }

    /**
     * Test get performance description method.
     *
     * @return void
     */
    public function test_get_performance_description()
    {
        $excellentInsight = AiInsight::create([
            'krayin_lead_id' => 123,
            'performance_score' => 9.5,
            'engagement_level' => AiInsight::ENGAGEMENT_HIGH,
            'is_current' => true,
        ]);

        $averageInsight = AiInsight::create([
            'krayin_lead_id' => 124,
            'performance_score' => 5.5,
            'engagement_level' => AiInsight::ENGAGEMENT_MEDIUM,
            'is_current' => true,
        ]);

        $this->assertEquals('Excellent', $excellentInsight->getPerformanceDescription());
        $this->assertEquals('Average', $averageInsight->getPerformanceDescription());
    }

    /**
     * Test get engagement description method.
     *
     * @return void
     */
    public function test_get_engagement_description()
    {
        $insight = AiInsight::create([
            'krayin_lead_id' => 123,
            'performance_score' => 7.5,
            'engagement_level' => AiInsight::ENGAGEMENT_HIGH,
            'is_current' => true,
        ]);

        $this->assertEquals('High', $insight->getEngagementDescription());
    }

    /**
     * Test get trend description method.
     *
     * @return void
     */
    public function test_get_trend_description()
    {
        $insight = AiInsight::create([
            'krayin_lead_id' => 123,
            'performance_score' => 7.5,
            'engagement_level' => AiInsight::ENGAGEMENT_HIGH,
            'trend' => AiInsight::TREND_IMPROVING,
            'is_current' => true,
        ]);

        $this->assertEquals('Improving', $insight->getTrendDescription());
    }

    /**
     * Test get summary method.
     *
     * @return void
     */
    public function test_get_summary()
    {
        $insight = AiInsight::create([
            'krayin_lead_id' => 123,
            'performance_score' => 7.5,
            'engagement_level' => AiInsight::ENGAGEMENT_HIGH,
            'trend' => AiInsight::TREND_IMPROVING,
            'total_conversations' => 10,
            'resolved_conversations' => 8,
            'resolution_rate' => 80.0,
            'is_current' => true,
        ]);

        $summary = $insight->getSummary();

        $this->assertIsArray($summary);
        $this->assertEquals(123, $summary['krayin_lead_id']);
        $this->assertEquals(7.5, $summary['performance_score']);
        $this->assertEquals('A', $summary['performance_grade']);
        $this->assertEquals('Good', $summary['performance_description']);
        $this->assertEquals(AiInsight::ENGAGEMENT_HIGH, $summary['engagement_level']);
        $this->assertEquals('High', $summary['engagement_description']);
        $this->assertEquals(AiInsight::TREND_IMPROVING, $summary['trend']);
        $this->assertEquals('Improving', $summary['trend_description']);
        $this->assertTrue($summary['is_high_performance']);
        $this->assertFalse($summary['is_low_performance']);
        $this->assertTrue($summary['is_high_engagement']);
        $this->assertFalse($summary['is_low_engagement']);
        $this->assertTrue($summary['is_improving']);
        $this->assertFalse($summary['is_declining']);
        $this->assertFalse($summary['is_stable']);
    }

    /**
     * Test create or update insight method.
     *
     * @return void
     */
    public function test_create_or_update_insight()
    {
        // Test creation
        $insight = AiInsight::createOrUpdateInsight([
            'krayin_lead_id' => 123,
            'performance_score' => 7.5,
            'engagement_level' => AiInsight::ENGAGEMENT_HIGH,
            'is_current' => true,
        ]);

        $this->assertInstanceOf(AiInsight::class, $insight);
        $this->assertEquals(123, $insight->krayin_lead_id);

        // Test update
        $updatedInsight = AiInsight::createOrUpdateInsight([
            'krayin_lead_id' => 123,
            'performance_score' => 8.5,
            'engagement_level' => AiInsight::ENGAGEMENT_HIGH,
            'is_current' => true,
        ]);

        $this->assertEquals(8.5, $updatedInsight->performance_score);
        $this->assertEquals($insight->id, $updatedInsight->id);
    }

    /**
     * Test get valid engagement levels method.
     *
     * @return void
     */
    public function test_get_valid_engagement_levels()
    {
        $levels = AiInsight::getValidEngagementLevels();

        $this->assertContains(AiInsight::ENGAGEMENT_LOW, $levels);
        $this->assertContains(AiInsight::ENGAGEMENT_MEDIUM, $levels);
        $this->assertContains(AiInsight::ENGAGEMENT_HIGH, $levels);
    }

    /**
     * Test get valid trends method.
     *
     * @return void
     */
    public function test_get_valid_trends()
    {
        $trends = AiInsight::getValidTrends();

        $this->assertContains(AiInsight::TREND_IMPROVING, $trends);
        $this->assertContains(AiInsight::TREND_STABLE, $trends);
        $this->assertContains(AiInsight::TREND_DECLINING, $trends);
    }

    /**
     * Test get performance score ranges method.
     *
     * @return void
     */
    public function test_get_performance_score_ranges()
    {
        $ranges = AiInsight::getPerformanceScoreRanges();

        $this->assertArrayHasKey('excellent', $ranges);
        $this->assertArrayHasKey('good', $ranges);
        $this->assertArrayHasKey('average', $ranges);
        $this->assertArrayHasKey('poor', $ranges);
        $this->assertArrayHasKey('very_poor', $ranges);

        $this->assertEquals([AiInsight::SCORE_EXCELLENT, 10.0], $ranges['excellent']);
        $this->assertEquals([AiInsight::SCORE_GOOD, AiInsight::SCORE_EXCELLENT], $ranges['good']);
    }
}
