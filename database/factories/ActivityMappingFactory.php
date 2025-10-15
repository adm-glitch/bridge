<?php

namespace Database\Factories;

use App\Models\ActivityMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ActivityMapping Factory
 * 
 * Factory for creating ActivityMapping test data.
 */
class ActivityMappingFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = ActivityMapping::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chatwoot_message_id' => $this->faker->unique()->numberBetween(1000, 9999),
            'krayin_activity_id' => $this->faker->numberBetween(100, 999),
            'conversation_id' => $this->faker->numberBetween(1000, 9999),
            'message_type' => $this->faker->randomElement([
                ActivityMapping::TYPE_INCOMING,
                ActivityMapping::TYPE_OUTGOING,
                ActivityMapping::TYPE_ACTIVITY,
            ]),
            'created_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
        ];
    }

    /**
     * Indicate that the activity is an incoming message.
     *
     * @return static
     */
    public function incoming(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'message_type' => ActivityMapping::TYPE_INCOMING,
            ];
        });
    }

    /**
     * Indicate that the activity is an outgoing message.
     *
     * @return static
     */
    public function outgoing(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'message_type' => ActivityMapping::TYPE_OUTGOING,
            ];
        });
    }

    /**
     * Indicate that the activity is a system activity.
     *
     * @return static
     */
    public function activity(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'message_type' => ActivityMapping::TYPE_ACTIVITY,
            ];
        });
    }

    /**
     * Indicate that the activity is recent.
     *
     * @return static
     */
    public function recent(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'created_at' => $this->faker->dateTimeBetween('-2 hours', 'now'),
            ];
        });
    }

    /**
     * Indicate that the activity is old.
     *
     * @return static
     */
    public function old(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'created_at' => $this->faker->dateTimeBetween('-30 days', '-7 days'),
            ];
        });
    }

    /**
     * Indicate that the activity is very old.
     *
     * @return static
     */
    public function veryOld(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'created_at' => $this->faker->dateTimeBetween('-90 days', '-30 days'),
            ];
        });
    }

    /**
     * Indicate that the activity is for a specific conversation.
     *
     * @param int $conversationId
     * @return static
     */
    public function forConversation(int $conversationId): static
    {
        return $this->state(function (array $attributes) use ($conversationId) {
            return [
                'conversation_id' => $conversationId,
            ];
        });
    }

    /**
     * Indicate that the activity is for a specific lead.
     *
     * @param int $leadId
     * @return static
     */
    public function forLead(int $leadId): static
    {
        return $this->state(function (array $attributes) use ($leadId) {
            return [
                'conversation_id' => $this->faker->numberBetween(1000, 9999),
                // Note: In real implementation, this would need to create a conversation mapping
            ];
        });
    }

    /**
     * Indicate that the activity has a specific Krayin activity ID.
     *
     * @param int $activityId
     * @return static
     */
    public function withKrayinActivity(int $activityId): static
    {
        return $this->state(function (array $attributes) use ($activityId) {
            return [
                'krayin_activity_id' => $activityId,
            ];
        });
    }

    /**
     * Indicate that the activity has a specific Chatwoot message ID.
     *
     * @param int $messageId
     * @return static
     */
    public function withChatwootMessage(int $messageId): static
    {
        return $this->state(function (array $attributes) use ($messageId) {
            return [
                'chatwoot_message_id' => $messageId,
            ];
        });
    }

    /**
     * Create a sequence of activities for a conversation.
     *
     * @param int $conversationId
     * @param int $count
     * @return static
     */
    public function sequenceForConversation(int $conversationId, int $count = 5): static
    {
        return $this->state(function (array $attributes) use ($conversationId, $count) {
            return [
                'conversation_id' => $conversationId,
                'created_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
            ];
        });
    }

    /**
     * Create activities with mixed message types.
     *
     * @return static
     */
    public function mixedTypes(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'message_type' => $this->faker->randomElement([
                    ActivityMapping::TYPE_INCOMING,
                    ActivityMapping::TYPE_OUTGOING,
                    ActivityMapping::TYPE_ACTIVITY,
                ]),
            ];
        });
    }

    /**
     * Create activities with specific time distribution.
     *
     * @param string $timeframe
     * @return static
     */
    public function timeDistribution(string $timeframe = 'recent'): static
    {
        return $this->state(function (array $attributes) use ($timeframe) {
            switch ($timeframe) {
                case 'recent':
                    return ['created_at' => $this->faker->dateTimeBetween('-2 hours', 'now')];
                case 'today':
                    return ['created_at' => $this->faker->dateTimeBetween('today', 'now')];
                case 'week':
                    return ['created_at' => $this->faker->dateTimeBetween('-7 days', 'now')];
                case 'month':
                    return ['created_at' => $this->faker->dateTimeBetween('-30 days', 'now')];
                default:
                    return ['created_at' => $this->faker->dateTimeBetween('-30 days', 'now')];
            }
        });
    }
}
