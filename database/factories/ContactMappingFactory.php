<?php

namespace Database\Factories;

use App\Models\ContactMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ContactMapping Factory
 * 
 * Factory for creating ContactMapping test data.
 */
class ContactMappingFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = ContactMapping::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chatwoot_contact_id' => $this->faker->unique()->numberBetween(1000, 9999),
            'krayin_lead_id' => $this->faker->optional(0.7)->numberBetween(100, 999),
            'krayin_person_id' => $this->faker->optional(0.3)->numberBetween(1000, 9999),
        ];
    }

    /**
     * Indicate that the mapping has a Krayin lead.
     *
     * @return static
     */
    public function withLead(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'krayin_lead_id' => $this->faker->numberBetween(100, 999),
                'krayin_person_id' => null,
            ];
        });
    }

    /**
     * Indicate that the mapping has a Krayin person.
     *
     * @return static
     */
    public function withPerson(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'krayin_lead_id' => null,
                'krayin_person_id' => $this->faker->numberBetween(1000, 9999),
            ];
        });
    }

    /**
     * Indicate that the mapping has both lead and person.
     *
     * @return static
     */
    public function withBoth(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'krayin_lead_id' => $this->faker->numberBetween(100, 999),
                'krayin_person_id' => $this->faker->numberBetween(1000, 9999),
            ];
        });
    }

    /**
     * Indicate that the mapping is recent.
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
}
