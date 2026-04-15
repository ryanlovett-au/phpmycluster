<?php

namespace Database\Factories;

use App\Enums\ClusterStatus;
use App\Models\Cluster;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cluster>
 */
class ClusterFactory extends Factory
{
    protected $model = Cluster::class;

    public function definition(): array
    {
        return [
            'name' => 'test-cluster',
            'communication_stack' => 'MYSQL',
            'mysql_version' => '8.4',
            'mysql_apt_config_version' => '0.8.33-1',
            'cluster_admin_user' => 'clusteradmin',
            'cluster_admin_password_encrypted' => 'testpassword',
            'status' => ClusterStatus::Pending,
            'ip_allowlist' => null,
            'last_status_json' => null,
            'last_checked_at' => null,
        ];
    }

    public function online(): static
    {
        return $this->state(fn () => ['status' => ClusterStatus::Online]);
    }

    public function degraded(): static
    {
        return $this->state(fn () => ['status' => ClusterStatus::Degraded]);
    }

    public function offline(): static
    {
        return $this->state(fn () => ['status' => ClusterStatus::Offline]);
    }
}
