<?php

namespace Database\Factories;

use App\Enums\MysqlNodeRole;
use App\Enums\MysqlNodeStatus;
use App\Models\MysqlCluster;
use App\Models\MysqlNode;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MysqlNode>
 */
class MysqlNodeFactory extends Factory
{
    protected $model = MysqlNode::class;

    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'cluster_id' => MysqlCluster::factory(),
            'name' => 'node-'.fake()->numberBetween(1, 99),
            'mysql_port' => 3306,
            'mysql_x_port' => 33060,
            'role' => MysqlNodeRole::Pending,
            'status' => MysqlNodeStatus::Unknown,
            'mysql_server_id' => fake()->numberBetween(1, 999),
            'mysql_installed' => false,
            'mysql_shell_installed' => false,
            'mysql_router_installed' => false,
            'mysql_configured' => false,
            'mysql_root_password_encrypted' => null,
            'last_health_json' => null,
            'last_checked_at' => null,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn () => [
            'role' => MysqlNodeRole::Primary,
            'status' => MysqlNodeStatus::Online,
            'mysql_installed' => true,
            'mysql_shell_installed' => true,
            'mysql_configured' => true,
        ]);
    }

    public function secondary(): static
    {
        return $this->state(fn () => [
            'role' => MysqlNodeRole::Secondary,
            'status' => MysqlNodeStatus::Online,
            'mysql_installed' => true,
            'mysql_shell_installed' => true,
            'mysql_configured' => true,
        ]);
    }

    public function access(): static
    {
        return $this->state(fn () => [
            'role' => MysqlNodeRole::Access,
            'status' => MysqlNodeStatus::Online,
            'mysql_router_installed' => true,
        ]);
    }

    public function offline(): static
    {
        return $this->state(fn () => ['status' => MysqlNodeStatus::Offline]);
    }
}
