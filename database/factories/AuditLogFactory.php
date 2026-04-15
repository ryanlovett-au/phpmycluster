<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\MysqlCluster;
use App\Models\MysqlNode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    public function definition(): array
    {
        return [
            'cluster_id' => MysqlCluster::factory(),
            'node_id' => null,
            'action' => 'test_action',
            'status' => 'success',
            'command' => 'echo "test"',
            'output' => 'test output',
            'error_message' => null,
            'duration_ms' => fake()->numberBetween(100, 5000),
        ];
    }

    /**
     * Associate the audit log with a specific node.
     */
    public function forNode(MysqlNode $node): static
    {
        return $this->state(fn () => [
            'cluster_id' => $node->cluster_id,
            'node_id' => $node->id,
        ]);
    }
}
