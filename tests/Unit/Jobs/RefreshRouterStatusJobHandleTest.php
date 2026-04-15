<?php

use App\Jobs\RefreshRouterStatusJob;
use App\Models\MysqlCluster;
use App\Models\MysqlNode;
use App\Services\MysqlProvisionService;

it('updates node to online when router is running', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->access()->create([
        'cluster_id' => $cluster->id,
        'status' => 'offline',
    ]);

    $provisionMock = Mockery::mock(MysqlProvisionService::class);
    $provisionMock->shouldReceive('getRouterStatus')
        ->once()
        ->with(Mockery::on(fn (MysqlNode $n) => $n->id === $node->id))
        ->andReturn([
            'running' => true,
            'output' => "active\nMySQLRouter 8.4.0",
        ]);

    $job = new RefreshRouterStatusJob($node);
    $job->handle($provisionMock);

    $node->refresh();
    expect($node->status->value)->toBe('online')
        ->and($node->last_checked_at)->not->toBeNull();
});

it('updates node to offline when router is not running', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->access()->create([
        'cluster_id' => $cluster->id,
        'status' => 'online',
    ]);

    $provisionMock = Mockery::mock(MysqlProvisionService::class);
    $provisionMock->shouldReceive('getRouterStatus')
        ->once()
        ->andReturn([
            'running' => false,
            'output' => 'inactive',
        ]);

    $job = new RefreshRouterStatusJob($node);
    $job->handle($provisionMock);

    $node->refresh();
    expect($node->status->value)->toBe('offline');
});

it('marks node as error when getRouterStatus throws', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->access()->create([
        'cluster_id' => $cluster->id,
        'status' => 'online',
    ]);

    $provisionMock = Mockery::mock(MysqlProvisionService::class);
    $provisionMock->shouldReceive('getRouterStatus')
        ->once()
        ->andThrow(new RuntimeException('SSH connection failed'));

    $job = new RefreshRouterStatusJob($node);
    $job->handle($provisionMock);

    $node->refresh();
    expect($node->status->value)->toBe('error')
        ->and($node->last_checked_at)->not->toBeNull();
});
