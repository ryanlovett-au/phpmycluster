<?php

use App\Jobs\RefreshRouterStatusJob;
use App\Models\MysqlNode;
use Illuminate\Bus\Batchable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses the Batchable trait', function () {
    $traits = class_uses_recursive(RefreshRouterStatusJob::class);
    expect($traits)->toContain(Batchable::class);
});

it('has a timeout of 30 seconds', function () {
    $node = MysqlNode::factory()->create();
    $job = new RefreshRouterStatusJob($node);

    expect($job->timeout)->toBe(30);
});

it('has tries set to 1', function () {
    $node = MysqlNode::factory()->create();
    $job = new RefreshRouterStatusJob($node);

    expect($job->tries)->toBe(1);
});

it('accepts a Node model in the constructor', function () {
    $node = MysqlNode::factory()->create();
    $job = new RefreshRouterStatusJob($node);

    expect($job->node)->toBeInstanceOf(MysqlNode::class);
    expect($job->node->id)->toBe($node->id);
});
