<?php

namespace Database\Factories;

use App\Models\AiInsight;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * AiInsight Factory
 * 
 * Factory for creating AiInsight test data.
 */
class AiInsightFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = AiInsight::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $totalConversations = $this->faker->numberBetween(1, 50);
        $resolvedConversations = $this->faker->numberBetween(0, $totalConversations);
        $pendingConversations = $totalConversations - $resolvedConversations;
        $resolutionRate = $totalConversations > 0 ? ($resolvedConversations / $totalConversations) * 100 : 0;

        return [
            'krayin_lead_id' => $this->faker->numberBetween(100, 999),
            'total_conversations' => $totalConversations,
            'resolved_conversations' => $resolvedConversations,
            'pending_conversations' => $pendingConversations,
            'resolution_rate' => round($resolutionRate, 2),
            'average_response_time_minutes' => $this->faker->numberBetween(5, 120),
            'total_messages' => $this->faker->numberBetween(10, 500),
            'average_messages_per_conversation' => $this->faker->randomFloat(2, 1, 20),
            'performance_score' => $this->faker->randomFloat(1, 0, 10),
            'engagement_level' => $this->faker->randomElement([
                AiInsight::ENGAGEMENT_LOW,
                AiInsight::ENGAGEMENT_MEDIUM,
                AiInsight::ENGAGEMENT_HIGH,
            ]),
            'trend' => $this->faker->optional(0.8)->randomElement([
                AiInsight::TREND_IMPROVING,
                AiInsight::TREND_STABLE,
                AiInsight::TREND_DECLINING,
            ]),
            'suggestions' => $this->faker->optional(0.7)->randomElements([
                'Follow up with customer',
                'Schedule call',
                'Send email',
                'Update contact information',
                'Review conversation history',
                'Escalate to manager',
                'Send follow-up materials',
                'Schedule demo',
            ], $this->faker->numberBetween(1, 3)),
            'last_interaction_at' => $this->faker->optional(0.9)->dateTimeBetween('-30 days', 'now'),
            'calculated_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'valid_from' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'valid_to' => $this->faker->optional(0.3)->dateTimeBetween('-30 days', 'now'),
            'is_current' => $this->faker->boolean(80), // 80% chance of being current
        ];
    }

    /**
     * Indicate that the insight is current.
     *
     * @return static
     */
    public function current(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'is_current' => true,
                'valid_to' => null,
            ];
        });
    }

    /**
     * Indicate that the insight is historical.
     *
     * @return static
     */
    public function historical(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'is_current' => false,
                'valid_to' => $this->faker->dateTimeBetween('-30 days', 'now'),
            ];
        });
    }

    /**
     * Indicate that the insight has high performance.
     *
     * @return static
     */
    public function highPerformance(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'performance_score' => $this->faker->randomFloat(1, AiInsight::SCORE_GOOD, 10.0),
                'engagement_level' => AiInsight::ENGAGEMENT_HIGH,
                'trend' => $this->faker->randomElement([
                    AiInsight::TREND_IMPROVING,
                    AiInsight::TREND_STABLE,
                ]),
            ];
        });
    }

    /**
     * Indicate that the insight has low performance.
     *
     * @return static
     */
    public function lowPerformance(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'performance_score' => $this->faker->randomFloat(1, 0, AiInsight::SCORE_AVERAGE),
                'engagement_level' => AiInsight::ENGAGEMENT_LOW,
                'trend' => $this->faker->randomElement([
                    AiInsight::TREND_DECLINING,
                    AiInsight::TREND_STABLE,
                ]),
            ];
        });
    }

    /**
     * Indicate that the insight has high engagement.
     *
     * @return static
     */
    public function highEngagement(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'engagement_level' => AiInsight::ENGAGEMENT_HIGH,
                'performance_score' => $this->faker->randomFloat(1, AiInsight::SCORE_AVERAGE, 10.0),
            ];
        });
    }

    /**
     * Indicate that the insight has low engagement.
     *
     * @return static
     */
    public function lowEngagement(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'engagement_level' => AiInsight::ENGAGEMENT_LOW,
                'performance_score' => $this->faker->randomFloat(1, 0, AiInsight::SCORE_GOOD),
            ];
        });
    }

    /**
     * Indicate that the insight is improving.
     *
     * @return static
     */
    public function improving(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'trend' => AiInsight::TREND_IMPROVING,
                'performance_score' => $this->faker->randomFloat(1, AiInsight::SCORE_AVERAGE, 10.0),
            ];
        });
    }

    /**
     * Indicate that the insight is declining.
     *
     * @return static
     */
    public function declining(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'trend' => AiInsight::TREND_DECLINING,
                'performance_score' => $this->faker->randomFloat(1, 0, AiInsight::SCORE_GOOD),
            ];
        });
    }

    /**
     * Indicate that the insight is stable.
     *
     * @return static
     */
    public function stable(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'trend' => AiInsight::TREND_STABLE,
                'performance_score' => $this->faker->randomFloat(1, AiInsight::SCORE_AVERAGE, AiInsight::SCORE_GOOD),
            ];
        });
    }

    /**
     * Indicate that the insight has excellent performance.
     *
     * @return static
     */
    public function excellent(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'performance_score' => $this->faker->randomFloat(1, AiInsight::SCORE_EXCELLENT, 10.0),
                'engagement_level' => AiInsight::ENGAGEMENT_HIGH,
                'trend' => AiInsight::TREND_IMPROVING,
                'resolution_rate' => $this->faker->randomFloat(2, 80, 100),
            ];
        });
    }

    /**
     * Indicate that the insight has poor performance.
     *
     * @return static
     */
    public function poor(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'performance_score' => $this->faker->randomFloat(1, 0, AiInsight::SCORE_POOR),
                'engagement_level' => AiInsight::ENGAGEMENT_LOW,
                'trend' => AiInsight::TREND_DECLINING,
                'resolution_rate' => $this->faker->randomFloat(2, 0, 50),
            ];
        });
    }

    /**
     * Indicate that the insight has recent activity.
     *
     * @return static
     */
    public function withRecentActivity(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'last_interaction_at' => $this->faker->dateTimeBetween('-2 days', 'now'),
                'calculated_at' => $this->faker->dateTimeBetween('-1 day', 'now'),
            ];
        });
    }

    /**
     * Indicate that the insight has old activity.
     *
     * @return static
     */
    public function withOldActivity(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'last_interaction_at' => $this->faker->dateTimeBetween('-30 days', '-7 days'),
                'calculated_at' => $this->faker->dateTimeBetween('-30 days', '-7 days'),
            ];
        });
    }

    /**
     * Indicate that the insight has many conversations.
     *
     * @return static
     */
    public function withManyConversations(): static
    {
        return $this->state(function (array $attributes) {
            $totalConversations = $this->faker->numberBetween(20, 100);
            $resolvedConversations = $this->faker->numberBetween(10, $totalConversations);
            $resolutionRate = ($resolvedConversations / $totalConversations) * 100;

            return [
                'total_conversations' => $totalConversations,
                'resolved_conversations' => $resolvedConversations,
                'pending_conversations' => $totalConversations - $resolvedConversations,
                'resolution_rate' => round($resolutionRate, 2),
            ];
        });
    }

    /**
     * Indicate that the insight has few conversations.
     *
     * @return static
     */
    public function withFewConversations(): static
    {
        return $this->state(function (array $attributes) {
            $totalConversations = $this->faker->numberBetween(1, 5);
            $resolvedConversations = $this->faker->numberBetween(0, $totalConversations);
            $resolutionRate = $totalConversations > 0 ? ($resolvedConversations / $totalConversations) * 100 : 0;

            return [
                'total_conversations' => $totalConversations,
                'resolved_conversations' => $resolvedConversations,
                'pending_conversations' => $totalConversations - $resolvedConversations,
                'resolution_rate' => round($resolutionRate, 2),
            ];
        });
    }

    /**
     * Indicate that the insight has specific suggestions.
     *
     * @param array $suggestions
     * @return static
     */
    public function withSuggestions(array $suggestions): static
    {
        return $this->state(function (array $attributes) use ($suggestions) {
            return [
                'suggestions' => $suggestions,
            ];
        });
    }

    /**
     * Indicate that the insight has no suggestions.
     *
     * @return static
     */
    public function withoutSuggestions(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'suggestions' => null,
            ];
        });
    }

    /**
     * Create a sequence of insights for a lead.
     *
     * @param int $leadId
     * @param int $count
     * @return static
     */
    public function sequenceForLead(int $leadId, int $count = 3): static
    {
        return $this->state(function (array $attributes) use ($leadId, $count) {
            return [
                'krayin_lead_id' => $leadId,
                'calculated_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
            ];
        });
    }
}
