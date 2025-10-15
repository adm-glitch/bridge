<?php

namespace Database\Factories;

use App\Models\ConsentRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ConsentRecord Factory
 * 
 * Factory for creating ConsentRecord test data.
 */
class ConsentRecordFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = ConsentRecord::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $consentType = $this->faker->randomElement([
            ConsentRecord::TYPE_DATA_PROCESSING,
            ConsentRecord::TYPE_MARKETING,
            ConsentRecord::TYPE_HEALTH_DATA,
        ]);

        $status = $this->faker->randomElement([
            ConsentRecord::STATUS_GRANTED,
            ConsentRecord::STATUS_DENIED,
            ConsentRecord::STATUS_WITHDRAWN,
        ]);

        $grantedAt = null;
        $withdrawnAt = null;

        if ($status === ConsentRecord::STATUS_GRANTED) {
            $grantedAt = $this->faker->dateTimeBetween('-30 days', 'now');
        } elseif ($status === ConsentRecord::STATUS_WITHDRAWN) {
            $grantedAt = $this->faker->dateTimeBetween('-30 days', '-1 day');
            $withdrawnAt = $this->faker->dateTimeBetween($grantedAt, 'now');
        }

        return [
            'contact_id' => $this->faker->numberBetween(100, 999),
            'chatwoot_contact_id' => $this->faker->optional(0.7)->numberBetween(1000, 9999),
            'krayin_lead_id' => $this->faker->optional(0.5)->numberBetween(100, 999),
            'consent_type' => $consentType,
            'status' => $status,
            'granted_at' => $grantedAt,
            'withdrawn_at' => $withdrawnAt,
            'ip_address' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
            'consent_text' => $this->faker->sentence(10),
            'consent_version' => $this->faker->randomElement(['1.0', '1.1', '2.0', '2.1']),
        ];
    }

    /**
     * Indicate that the consent is granted.
     *
     * @return static
     */
    public function granted(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => ConsentRecord::STATUS_GRANTED,
                'granted_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
                'withdrawn_at' => null,
            ];
        });
    }

    /**
     * Indicate that the consent is denied.
     *
     * @return static
     */
    public function denied(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => ConsentRecord::STATUS_DENIED,
                'granted_at' => null,
                'withdrawn_at' => null,
            ];
        });
    }

    /**
     * Indicate that the consent is withdrawn.
     *
     * @return static
     */
    public function withdrawn(): static
    {
        return $this->state(function (array $attributes) {
            $grantedAt = $this->faker->dateTimeBetween('-30 days', '-1 day');
            return [
                'status' => ConsentRecord::STATUS_WITHDRAWN,
                'granted_at' => $grantedAt,
                'withdrawn_at' => $this->faker->dateTimeBetween($grantedAt, 'now'),
            ];
        });
    }

    /**
     * Indicate that the consent is for data processing.
     *
     * @return static
     */
    public function dataProcessing(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            ];
        });
    }

    /**
     * Indicate that the consent is for marketing.
     *
     * @return static
     */
    public function marketing(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'consent_type' => ConsentRecord::TYPE_MARKETING,
            ];
        });
    }

    /**
     * Indicate that the consent is for health data.
     *
     * @return static
     */
    public function healthData(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'consent_type' => ConsentRecord::TYPE_HEALTH_DATA,
            ];
        });
    }

    /**
     * Indicate that the consent is valid (granted and not withdrawn).
     *
     * @return static
     */
    public function valid(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => ConsentRecord::STATUS_GRANTED,
                'granted_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
                'withdrawn_at' => null,
            ];
        });
    }

    /**
     * Indicate that the consent is recent.
     *
     * @return static
     */
    public function recent(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'granted_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
                'created_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
            ];
        });
    }

    /**
     * Indicate that the consent is old.
     *
     * @return static
     */
    public function old(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'granted_at' => $this->faker->dateTimeBetween('-2 years', '-1 year'),
                'created_at' => $this->faker->dateTimeBetween('-2 years', '-1 year'),
            ];
        });
    }

    /**
     * Indicate that the consent is expired (older than 5 years).
     *
     * @return static
     */
    public function expired(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'granted_at' => $this->faker->dateTimeBetween('-7 years', '-5 years'),
                'created_at' => $this->faker->dateTimeBetween('-7 years', '-5 years'),
            ];
        });
    }

    /**
     * Indicate that the consent is for a specific contact.
     *
     * @param int $contactId
     * @return static
     */
    public function forContact(int $contactId): static
    {
        return $this->state(function (array $attributes) use ($contactId) {
            return [
                'contact_id' => $contactId,
            ];
        });
    }

    /**
     * Indicate that the consent is for a specific Chatwoot contact.
     *
     * @param int $chatwootContactId
     * @return static
     */
    public function forChatwootContact(int $chatwootContactId): static
    {
        return $this->state(function (array $attributes) use ($chatwootContactId) {
            return [
                'chatwoot_contact_id' => $chatwootContactId,
            ];
        });
    }

    /**
     * Indicate that the consent is for a specific Krayin lead.
     *
     * @param int $krayinLeadId
     * @return static
     */
    public function forKrayinLead(int $krayinLeadId): static
    {
        return $this->state(function (array $attributes) use ($krayinLeadId) {
            return [
                'krayin_lead_id' => $krayinLeadId,
            ];
        });
    }

    /**
     * Indicate that the consent has a specific version.
     *
     * @param string $version
     * @return static
     */
    public function withVersion(string $version): static
    {
        return $this->state(function (array $attributes) use ($version) {
            return [
                'consent_version' => $version,
            ];
        });
    }

    /**
     * Indicate that the consent has a specific IP address.
     *
     * @param string $ipAddress
     * @return static
     */
    public function withIpAddress(string $ipAddress): static
    {
        return $this->state(function (array $attributes) use ($ipAddress) {
            return [
                'ip_address' => $ipAddress,
            ];
        });
    }

    /**
     * Indicate that the consent has a specific user agent.
     *
     * @param string $userAgent
     * @return static
     */
    public function withUserAgent(string $userAgent): static
    {
        return $this->state(function (array $attributes) use ($userAgent) {
            return [
                'user_agent' => $userAgent,
            ];
        });
    }

    /**
     * Indicate that the consent has a specific consent text.
     *
     * @param string $consentText
     * @return static
     */
    public function withConsentText(string $consentText): static
    {
        return $this->state(function (array $attributes) use ($consentText) {
            return [
                'consent_text' => $consentText,
            ];
        });
    }

    /**
     * Create a sequence of consents for a contact.
     *
     * @param int $contactId
     * @param int $count
     * @return static
     */
    public function sequenceForContact(int $contactId, int $count = 3): static
    {
        return $this->state(function (array $attributes) use ($contactId, $count) {
            return [
                'contact_id' => $contactId,
                'created_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
            ];
        });
    }

    /**
     * Create consents with specific time distribution.
     *
     * @param string $timeframe
     * @return static
     */
    public function timeDistribution(string $timeframe = 'recent'): static
    {
        return $this->state(function (array $attributes) use ($timeframe) {
            switch ($timeframe) {
                case 'recent':
                    return [
                        'granted_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
                        'created_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
                    ];
                case 'today':
                    return [
                        'granted_at' => $this->faker->dateTimeBetween('today', 'now'),
                        'created_at' => $this->faker->dateTimeBetween('today', 'now'),
                    ];
                case 'week':
                    return [
                        'granted_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
                        'created_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
                    ];
                case 'month':
                    return [
                        'granted_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
                        'created_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
                    ];
                case 'year':
                    return [
                        'granted_at' => $this->faker->dateTimeBetween('-1 year', 'now'),
                        'created_at' => $this->faker->dateTimeBetween('-1 year', 'now'),
                    ];
                default:
                    return [
                        'granted_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
                        'created_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
                    ];
            }
        });
    }

    /**
     * Create consents with specific consent type distribution.
     *
     * @param array $types
     * @return static
     */
    public function withConsentTypes(array $types): static
    {
        return $this->state(function (array $attributes) use ($types) {
            return [
                'consent_type' => $this->faker->randomElement($types),
            ];
        });
    }

    /**
     * Create consents with specific status distribution.
     *
     * @param array $statuses
     * @return static
     */
    public function withStatuses(array $statuses): static
    {
        return $this->state(function (array $attributes) use ($statuses) {
            $status = $this->faker->randomElement($statuses);
            $grantedAt = null;
            $withdrawnAt = null;

            if ($status === ConsentRecord::STATUS_GRANTED) {
                $grantedAt = $this->faker->dateTimeBetween('-30 days', 'now');
            } elseif ($status === ConsentRecord::STATUS_WITHDRAWN) {
                $grantedAt = $this->faker->dateTimeBetween('-30 days', '-1 day');
                $withdrawnAt = $this->faker->dateTimeBetween($grantedAt, 'now');
            }

            return [
                'status' => $status,
                'granted_at' => $grantedAt,
                'withdrawn_at' => $withdrawnAt,
            ];
        });
    }
}
