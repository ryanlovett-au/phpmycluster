<?php

namespace Database\Factories;

use App\Enums\RedisClusterStatus;
use App\Models\RedisCluster;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RedisCluster>
 */
class RedisClusterFactory extends Factory
{
    protected $model = RedisCluster::class;

    public function definition(): array
    {
        return [
            'name' => 'test-redis-cluster',
            'redis_version' => null,
            'auth_password_encrypted' => 'testredispassword',
            'sentinel_password_encrypted' => 'testsentinelpassword',
            'quorum' => 2,
            'down_after_milliseconds' => 5000,
            'failover_timeout' => 60000,
            'status' => RedisClusterStatus::Pending,
            'last_status_json' => null,
            'last_checked_at' => null,
        ];
    }

    public function online(): static
    {
        return $this->state(fn () => ['status' => RedisClusterStatus::Online]);
    }

    public function degraded(): static
    {
        return $this->state(fn () => ['status' => RedisClusterStatus::Degraded]);
    }

    public function offline(): static
    {
        return $this->state(fn () => ['status' => RedisClusterStatus::Offline]);
    }
}
