<?php

namespace Database\Factories;

use App\Models\ConversationMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ConversationMapping Factory
 * 
 * Factory for creating ConversationMapping test data.
 */
class ConversationMappingFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = ConversationMapping::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chatwoot_conversation_id' => $this->faker->unique()->numberBetween(1000, 9999),
            'krayin_lead_id' => $this->faker->numberBetween(100, 999),
            'status' => $this->faker->randomElement([
                ConversationMapping::STATUS_OPEN,
                ConversationMapping::STATUS_RESOLVED,
                ConversationMapping::STATUS_PENDING,
                ConversationMapping::STATUS_SNOOZED,
            ]),
            'message_count' => $this->faker->numberBetween(0, 50),
            'last_message_at' => $this->faker->optional(0.8)->dateTimeBetween('-30 days', 'now'),
            'first_response_at' => $this->faker->optional(0.7)->dateTimeBetween('-30 days', 'now'),
            'resolved_at' => $this->faker->optional(0.3)->dateTimeBetween('-30 days', 'now'),
        ];
    }

    /**
     * Indicate that the conversation is open.
     *
     * @return static
     */
    public function open(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => ConversationMapping::STATUS_OPEN,
                'resolved_at' => null,
            ];
        });
    }

    /**
     * Indicate that the conversation is resolved.
     *
     * @return static
     */
    public function resolved(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => ConversationMapping::STATUS_RESOLVED,
                'resolved_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
            ];
        });
    }

    /**
     * Indicate that the conversation is pending.
     *
     * @return static
     */
    public function pending(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => ConversationMapping::STATUS_PENDING,
                'resolved_at' => null,
            ];
        });
    }

    /**
     * Indicate that the conversation is snoozed.
     *
     * @return static
     */
    public function snoozed(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => ConversationMapping::STATUS_SNOOZED,
                'resolved_at' => null,
            ];
        });
    }

    /**
     * Indicate that the conversation has recent activity.
     *
     * @return static
     */
    public function withRecentActivity(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'last_message_at' => $this->faker->dateTimeBetween('-2 hours', 'now'),
            ];
        });
    }

    /**
     * Indicate that the conversation has old activity.
     *
     * @return static
     */
    public function withOldActivity(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'last_message_at' => $this->faker->dateTimeBetween('-30 days', '-7 days'),
            ];
        });
    }

    /**
     * Indicate that the conversation has many messages.
     *
     * @return static
     */
    public function withManyMessages(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'message_count' => $this->faker->numberBetween(20, 100),
            ];
        });
    }

    /**
     * Indicate that the conversation has few messages.
     *
     * @return static
     */
    public function withFewMessages(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'message_count' => $this->faker->numberBetween(0, 5),
            ];
        });
    }

    /**
     * Indicate that the conversation has first response.
     *
     * @return static
     */
    public function withFirstResponse(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'first_response_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
            ];
        });
    }

    /**
     * Indicate that the conversation is recent.
     *
     * @return static
     */
    public function recent(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'created_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
                'updated_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
            ];
        });
    }

    /**
     * Indicate that the conversation is old.
     *
     * @return static
     */
    public function old(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'created_at' => $this->faker->dateTimeBetween('-90 days', '-30 days'),
                'updated_at' => $this->faker->dateTimeBetween('-90 days', '-30 days'),
            ];
        });
    }
}
