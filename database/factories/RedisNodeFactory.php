<?php

namespace Database\Factories;

use App\Enums\RedisNodeRole;
use App\Enums\RedisNodeStatus;
use App\Models\RedisCluster;
use App\Models\RedisNode;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RedisNode>
 */
class RedisNodeFactory extends Factory
{
    protected $model = RedisNode::class;

    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'redis_cluster_id' => RedisCluster::factory(),
            'name' => 'redis-node-'.fake()->numberBetween(1, 99),
            'redis_port' => 6379,
            'sentinel_port' => 26379,
            'role' => RedisNodeRole::Pending,
            'status' => RedisNodeStatus::Unknown,
            'redis_installed' => false,
            'sentinel_installed' => false,
            'redis_configured' => false,
            'redis_version' => null,
            'last_health_json' => null,
            'last_checked_at' => null,
        ];
    }

    public function master(): static
    {
        return $this->state(fn () => [
            'role' => RedisNodeRole::Master,
            'status' => RedisNodeStatus::Online,
            'redis_installed' => true,
            'sentinel_installed' => true,
            'redis_configured' => true,
        ]);
    }

    public function replica(): static
    {
        return $this->state(fn () => [
            'role' => RedisNodeRole::Replica,
            'status' => RedisNodeStatus::Online,
            'redis_installed' => true,
            'sentinel_installed' => true,
            'redis_configured' => true,
        ]);
    }

    public function offline(): static
    {
        return $this->state(fn () => ['status' => RedisNodeStatus::Offline]);
    }
}
