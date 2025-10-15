<?php

namespace Database\Factories;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * AuditLog Factory
 * 
 * Factory for creating AuditLog test data.
 */
class AuditLogFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = AuditLog::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $action = $this->faker->randomElement([
            AuditLog::ACTION_CREATE,
            AuditLog::ACTION_READ,
            AuditLog::ACTION_UPDATE,
            AuditLog::ACTION_DELETE,
            AuditLog::ACTION_EXPORT,
            AuditLog::ACTION_LOGIN,
            AuditLog::ACTION_LOGOUT,
            AuditLog::ACTION_ACCESS,
        ]);

        $model = $this->faker->randomElement([
            AuditLog::MODEL_CONTACT,
            AuditLog::MODEL_LEAD,
            AuditLog::MODEL_CONVERSATION,
            AuditLog::MODEL_ACTIVITY,
            AuditLog::MODEL_CONSENT,
            AuditLog::MODEL_USER,
        ]);

        $changes = null;
        if (in_array($action, [AuditLog::ACTION_UPDATE, AuditLog::ACTION_CREATE])) {
            $changes = [
                'name' => $this->faker->name(),
                'email' => $this->faker->email(),
                'phone' => $this->faker->phoneNumber(),
            ];
        }

        return [
            'user_id' => $this->faker->optional(0.8)->numberBetween(1, 100),
            'action' => $action,
            'model' => $model,
            'model_id' => $this->faker->optional(0.9)->numberBetween(1, 1000),
            'changes' => $changes,
            'ip_address' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
        ];
    }

    /**
     * Indicate that the audit log is a create action.
     *
     * @return static
     */
    public function createAction(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'action' => AuditLog::ACTION_CREATE,
                'changes' => [
                    'name' => $this->faker->name(),
                    'email' => $this->faker->email(),
                ],
            ];
        });
    }

    /**
     * Indicate that the audit log is a read action.
     *
     * @return static
     */
    public function read(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'action' => AuditLog::ACTION_READ,
                'changes' => null,
            ];
        });
    }

    /**
     * Indicate that the audit log is an update action.
     *
     * @return static
     */
    public function update(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'action' => AuditLog::ACTION_UPDATE,
                'changes' => [
                    'old' => ['name' => $this->faker->name()],
                    'new' => ['name' => $this->faker->name()],
                ],
            ];
        });
    }

    /**
     * Indicate that the audit log is a delete action.
     *
     * @return static
     */
    public function delete(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'action' => AuditLog::ACTION_DELETE,
                'changes' => [
                    'deleted_data' => [
                        'name' => $this->faker->name(),
                        'email' => $this->faker->email(),
                    ],
                ],
            ];
        });
    }

    /**
     * Indicate that the audit log is an export action.
     *
     * @return static
     */
    public function export(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'action' => AuditLog::ACTION_EXPORT,
                'changes' => [
                    'exported_fields' => ['name', 'email', 'phone'],
                    'export_format' => 'json',
                ],
            ];
        });
    }

    /**
     * Indicate that the audit log is a login action.
     *
     * @return static
     */
    public function login(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'action' => AuditLog::ACTION_LOGIN,
                'model' => AuditLog::MODEL_USER,
                'changes' => [
                    'login_method' => 'password',
                    'login_time' => now()->toISOString(),
                ],
            ];
        });
    }

    /**
     * Indicate that the audit log is a logout action.
     *
     * @return static
     */
    public function logout(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'action' => AuditLog::ACTION_LOGOUT,
                'model' => AuditLog::MODEL_USER,
                'changes' => [
                    'logout_time' => now()->toISOString(),
                    'session_duration' => $this->faker->numberBetween(300, 3600),
                ],
            ];
        });
    }

    /**
     * Indicate that the audit log is an access action.
     *
     * @return static
     */
    public function access(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'action' => AuditLog::ACTION_ACCESS,
                'changes' => [
                    'accessed_resource' => $this->faker->randomElement(['dashboard', 'contacts', 'leads', 'conversations']),
                    'access_method' => 'web',
                ],
            ];
        });
    }

    /**
     * Indicate that the audit log is for a contact.
     *
     * @return static
     */
    public function contact(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'model' => AuditLog::MODEL_CONTACT,
                'model_id' => $this->faker->numberBetween(1, 1000),
            ];
        });
    }

    /**
     * Indicate that the audit log is for a lead.
     *
     * @return static
     */
    public function lead(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'model' => AuditLog::MODEL_LEAD,
                'model_id' => $this->faker->numberBetween(1, 1000),
            ];
        });
    }

    /**
     * Indicate that the audit log is for a conversation.
     *
     * @return static
     */
    public function conversation(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'model' => AuditLog::MODEL_CONVERSATION,
                'model_id' => $this->faker->numberBetween(1, 1000),
            ];
        });
    }

    /**
     * Indicate that the audit log is for an activity.
     *
     * @return static
     */
    public function activity(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'model' => AuditLog::MODEL_ACTIVITY,
                'model_id' => $this->faker->numberBetween(1, 1000),
            ];
        });
    }

    /**
     * Indicate that the audit log is for a consent record.
     *
     * @return static
     */
    public function consent(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'model' => AuditLog::MODEL_CONSENT,
                'model_id' => $this->faker->numberBetween(1, 1000),
            ];
        });
    }

    /**
     * Indicate that the audit log is for a user.
     *
     * @return static
     */
    public function user(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'model' => AuditLog::MODEL_USER,
                'model_id' => $this->faker->numberBetween(1, 100),
            ];
        });
    }

    /**
     * Indicate that the audit log is for a specific user.
     *
     * @param int $userId
     * @return static
     */
    public function forUser(int $userId): static
    {
        return $this->state(function (array $attributes) use ($userId) {
            return [
                'user_id' => $userId,
            ];
        });
    }

    /**
     * Indicate that the audit log is for a specific model and ID.
     *
     * @param string $model
     * @param int $modelId
     * @return static
     */
    public function forModel(string $model, int $modelId): static
    {
        return $this->state(function (array $attributes) use ($model, $modelId) {
            return [
                'model' => $model,
                'model_id' => $modelId,
            ];
        });
    }

    /**
     * Indicate that the audit log is recent.
     *
     * @return static
     */
    public function recent(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'created_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
            ];
        });
    }

    /**
     * Indicate that the audit log is old.
     *
     * @return static
     */
    public function old(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'created_at' => $this->faker->dateTimeBetween('-1 year', '-1 month'),
            ];
        });
    }

    /**
     * Indicate that the audit log has changes.
     *
     * @return static
     */
    public function withChanges(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'changes' => [
                    'field1' => $this->faker->word(),
                    'field2' => $this->faker->word(),
                    'field3' => $this->faker->word(),
                ],
            ];
        });
    }

    /**
     * Indicate that the audit log has no changes.
     *
     * @return static
     */
    public function withoutChanges(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'changes' => null,
            ];
        });
    }

    /**
     * Indicate that the audit log has a specific IP address.
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
     * Indicate that the audit log has a specific user agent.
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
     * Create a sequence of audit logs for a user.
     *
     * @param int $userId
     * @param int $count
     * @return static
     */
    public function sequenceForUser(int $userId, int $count = 5): static
    {
        return $this->state(function (array $attributes) use ($userId, $count) {
            return [
                'user_id' => $userId,
                'created_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
            ];
        });
    }

    /**
     * Create audit logs with specific time distribution.
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
                        'created_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
                    ];
                case 'today':
                    return [
                        'created_at' => $this->faker->dateTimeBetween('today', 'now'),
                    ];
                case 'week':
                    return [
                        'created_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
                    ];
                case 'month':
                    return [
                        'created_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
                    ];
                case 'year':
                    return [
                        'created_at' => $this->faker->dateTimeBetween('-1 year', 'now'),
                    ];
                default:
                    return [
                        'created_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
                    ];
            }
        });
    }

    /**
     * Create audit logs with specific action distribution.
     *
     * @param array $actions
     * @return static
     */
    public function withActions(array $actions): static
    {
        return $this->state(function (array $attributes) use ($actions) {
            return [
                'action' => $this->faker->randomElement($actions),
            ];
        });
    }

    /**
     * Create audit logs with specific model distribution.
     *
     * @param array $models
     * @return static
     */
    public function withModels(array $models): static
    {
        return $this->state(function (array $attributes) use ($models) {
            return [
                'model' => $this->faker->randomElement($models),
            ];
        });
    }
}
