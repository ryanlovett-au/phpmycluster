<?php

namespace Database\Factories;

use App\Enums\MysqlClusterStatus;
use App\Models\MysqlCluster;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MysqlCluster>
 */
class MysqlClusterFactory extends Factory
{
    protected $model = MysqlCluster::class;

    public function definition(): array
    {
        return [
            'name' => 'test-cluster',
            'communication_stack' => 'MYSQL',
            'mysql_version' => '8.4',
            'mysql_apt_config_version' => '0.8.33-1',
            'cluster_admin_user' => 'clusteradmin',
            'cluster_admin_password_encrypted' => 'testpassword',
            'status' => MysqlClusterStatus::Pending,
            'ip_allowlist' => null,
            'last_status_json' => null,
            'last_checked_at' => null,
        ];
    }

    public function online(): static
    {
        return $this->state(fn () => ['status' => MysqlClusterStatus::Online]);
    }

    public function degraded(): static
    {
        return $this->state(fn () => ['status' => MysqlClusterStatus::Degraded]);
    }

    public function offline(): static
    {
        return $this->state(fn () => ['status' => MysqlClusterStatus::Offline]);
    }
}
