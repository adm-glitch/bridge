<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * User Factory
 * 
 * Factory for creating User test data.
 */
class UserFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = User::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => Hash::make('password123'),
            'phone' => $this->faker->optional(0.7)->phoneNumber(),
            'avatar' => $this->faker->optional(0.3)->imageUrl(100, 100, 'people'),
            'role' => $this->faker->randomElement([
                User::ROLE_ADMIN,
                User::ROLE_MANAGER,
                User::ROLE_AGENT,
                User::ROLE_VIEWER,
            ]),
            'status' => $this->faker->randomElement([
                User::STATUS_ACTIVE,
                User::STATUS_INACTIVE,
                User::STATUS_SUSPENDED,
                User::STATUS_PENDING,
            ]),
            'last_login_at' => $this->faker->optional(0.8)->dateTimeBetween('-30 days', 'now'),
            'last_login_ip' => $this->faker->optional(0.8)->ipv4(),
            'timezone' => $this->faker->randomElement([
                'UTC',
                'America/New_York',
                'America/Los_Angeles',
                'Europe/London',
                'Asia/Tokyo',
            ]),
            'language' => $this->faker->randomElement(['en', 'es', 'pt', 'fr', 'de']),
            'preferences' => [
                'theme' => $this->faker->randomElement(['light', 'dark']),
                'notifications' => $this->faker->boolean(),
                'email_digest' => $this->faker->boolean(),
            ],
            'permissions' => $this->faker->randomElements([
                'read',
                'write',
                'delete',
                'admin',
                'export',
                'import',
            ], $this->faker->numberBetween(1, 4)),
            'notes' => $this->faker->optional(0.3)->sentence(),
        ];
    }

    /**
     * Indicate that the user is an admin.
     *
     * @return static
     */
    public function admin(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'role' => User::ROLE_ADMIN,
                'status' => User::STATUS_ACTIVE,
                'permissions' => ['read', 'write', 'delete', 'admin', 'export', 'import'],
            ];
        });
    }

    /**
     * Indicate that the user is a manager.
     *
     * @return static
     */
    public function manager(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'role' => User::ROLE_MANAGER,
                'status' => User::STATUS_ACTIVE,
                'permissions' => ['read', 'write', 'delete', 'export'],
            ];
        });
    }

    /**
     * Indicate that the user is an agent.
     *
     * @return static
     */
    public function agent(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'role' => User::ROLE_AGENT,
                'status' => User::STATUS_ACTIVE,
                'permissions' => ['read', 'write'],
            ];
        });
    }

    /**
     * Indicate that the user is a viewer.
     *
     * @return static
     */
    public function viewer(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'role' => User::ROLE_VIEWER,
                'status' => User::STATUS_ACTIVE,
                'permissions' => ['read'],
            ];
        });
    }

    /**
     * Indicate that the user is active.
     *
     * @return static
     */
    public function active(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => User::STATUS_ACTIVE,
                'last_login_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
            ];
        });
    }

    /**
     * Indicate that the user is inactive.
     *
     * @return static
     */
    public function inactive(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => User::STATUS_INACTIVE,
                'last_login_at' => $this->faker->dateTimeBetween('-30 days', '-7 days'),
            ];
        });
    }

    /**
     * Indicate that the user is suspended.
     *
     * @return static
     */
    public function suspended(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => User::STATUS_SUSPENDED,
                'last_login_at' => $this->faker->dateTimeBetween('-60 days', '-30 days'),
            ];
        });
    }

    /**
     * Indicate that the user is pending.
     *
     * @return static
     */
    public function pending(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => User::STATUS_PENDING,
                'last_login_at' => null,
            ];
        });
    }

    /**
     * Indicate that the user has recent activity.
     *
     * @return static
     */
    public function withRecentActivity(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'last_login_at' => $this->faker->dateTimeBetween('-3 days', 'now'),
                'last_login_ip' => $this->faker->ipv4(),
            ];
        });
    }

    /**
     * Indicate that the user has no recent activity.
     *
     * @return static
     */
    public function withoutRecentActivity(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'last_login_at' => $this->faker->dateTimeBetween('-60 days', '-30 days'),
                'last_login_ip' => $this->faker->ipv4(),
            ];
        });
    }

    /**
     * Indicate that the user has specific permissions.
     *
     * @param array $permissions
     * @return static
     */
    public function withPermissions(array $permissions): static
    {
        return $this->state(function (array $attributes) use ($permissions) {
            return [
                'permissions' => $permissions,
            ];
        });
    }

    /**
     * Indicate that the user has no permissions.
     *
     * @return static
     */
    public function withoutPermissions(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'permissions' => [],
            ];
        });
    }

    /**
     * Indicate that the user has specific preferences.
     *
     * @param array $preferences
     * @return static
     */
    public function withPreferences(array $preferences): static
    {
        return $this->state(function (array $attributes) use ($preferences) {
            return [
                'preferences' => array_merge($attributes['preferences'] ?? [], $preferences),
            ];
        });
    }

    /**
     * Indicate that the user has a specific timezone.
     *
     * @param string $timezone
     * @return static
     */
    public function withTimezone(string $timezone): static
    {
        return $this->state(function (array $attributes) use ($timezone) {
            return [
                'timezone' => $timezone,
            ];
        });
    }

    /**
     * Indicate that the user has a specific language.
     *
     * @param string $language
     * @return static
     */
    public function withLanguage(string $language): static
    {
        return $this->state(function (array $attributes) use ($language) {
            return [
                'language' => $language,
            ];
        });
    }

    /**
     * Indicate that the user has a specific email domain.
     *
     * @param string $domain
     * @return static
     */
    public function withEmailDomain(string $domain): static
    {
        return $this->state(function (array $attributes) use ($domain) {
            return [
                'email' => $this->faker->userName() . '@' . $domain,
            ];
        });
    }

    /**
     * Indicate that the user has a specific phone number.
     *
     * @param string $phone
     * @return static
     */
    public function withPhone(string $phone): static
    {
        return $this->state(function (array $attributes) use ($phone) {
            return [
                'phone' => $phone,
            ];
        });
    }

    /**
     * Indicate that the user has an avatar.
     *
     * @return static
     */
    public function withAvatar(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'avatar' => $this->faker->imageUrl(100, 100, 'people'),
            ];
        });
    }

    /**
     * Indicate that the user has no avatar.
     *
     * @return static
     */
    public function withoutAvatar(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'avatar' => null,
            ];
        });
    }

    /**
     * Indicate that the user has notes.
     *
     * @return static
     */
    public function withNotes(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'notes' => $this->faker->sentence(),
            ];
        });
    }

    /**
     * Indicate that the user has no notes.
     *
     * @return static
     */
    public function withoutNotes(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'notes' => null,
            ];
        });
    }

    /**
     * Create a sequence of users with different roles.
     *
     * @param int $count
     * @return static
     */
    public function withDifferentRoles(int $count = 4): static
    {
        return $this->state(function (array $attributes) use ($count) {
            $roles = [
                User::ROLE_ADMIN,
                User::ROLE_MANAGER,
                User::ROLE_AGENT,
                User::ROLE_VIEWER,
            ];

            return [
                'role' => $this->faker->randomElement($roles),
            ];
        });
    }

    /**
     * Create a sequence of users with different statuses.
     *
     * @param int $count
     * @return static
     */
    public function withDifferentStatuses(int $count = 4): static
    {
        return $this->state(function (array $attributes) use ($count) {
            $statuses = [
                User::STATUS_ACTIVE,
                User::STATUS_INACTIVE,
                User::STATUS_SUSPENDED,
                User::STATUS_PENDING,
            ];

            return [
                'status' => $this->faker->randomElement($statuses),
            ];
        });
    }

    /**
     * Create a sequence of users with different timezones.
     *
     * @param int $count
     * @return static
     */
    public function withDifferentTimezones(int $count = 5): static
    {
        return $this->state(function (array $attributes) use ($count) {
            $timezones = [
                'UTC',
                'America/New_York',
                'America/Los_Angeles',
                'Europe/London',
                'Asia/Tokyo',
            ];

            return [
                'timezone' => $this->faker->randomElement($timezones),
            ];
        });
    }

    /**
     * Create a sequence of users with different languages.
     *
     * @param int $count
     * @return static
     */
    public function withDifferentLanguages(int $count = 5): static
    {
        return $this->state(function (array $attributes) use ($count) {
            $languages = ['en', 'es', 'pt', 'fr', 'de'];

            return [
                'language' => $this->faker->randomElement($languages),
            ];
        });
    }

    /**
     * Create a sequence of users with different login patterns.
     *
     * @param int $count
     * @return static
     */
    public function withDifferentLoginPatterns(int $count = 3): static
    {
        return $this->state(function (array $attributes) use ($count) {
            $patterns = [
                'recent' => [
                    'last_login_at' => $this->faker->dateTimeBetween('-3 days', 'now'),
                    'last_login_ip' => $this->faker->ipv4(),
                ],
                'old' => [
                    'last_login_at' => $this->faker->dateTimeBetween('-30 days', '-7 days'),
                    'last_login_ip' => $this->faker->ipv4(),
                ],
                'never' => [
                    'last_login_at' => null,
                    'last_login_ip' => null,
                ],
            ];

            $pattern = $this->faker->randomElement(array_keys($patterns));
            return $patterns[$pattern];
        });
    }
}
