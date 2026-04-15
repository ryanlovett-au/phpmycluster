<?php

use App\Jobs\RefreshDbStatusJob;
use App\Models\MysqlCluster;
use Illuminate\Bus\Batchable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses the Batchable trait', function () {
    $traits = class_uses_recursive(RefreshDbStatusJob::class);
    expect($traits)->toContain(Batchable::class);
});

it('has a timeout of 120 seconds', function () {
    $cluster = MysqlCluster::factory()->create();
    $job = new RefreshDbStatusJob($cluster);

    expect($job->timeout)->toBe(120);
});

it('has tries set to 1', function () {
    $cluster = MysqlCluster::factory()->create();
    $job = new RefreshDbStatusJob($cluster);

    expect($job->tries)->toBe(1);
});

it('can be serialized', function () {
    $cluster = MysqlCluster::factory()->create();
    $job = new RefreshDbStatusJob($cluster);

    $serialized = serialize($job);
    $unserialized = unserialize($serialized);

    expect($unserialized)->toBeInstanceOf(RefreshDbStatusJob::class);
    expect($unserialized->cluster->id)->toBe($cluster->id);
});

it('accepts a Cluster model in the constructor', function () {
    $cluster = MysqlCluster::factory()->create();
    $job = new RefreshDbStatusJob($cluster);

    expect($job->cluster)->toBeInstanceOf(MysqlCluster::class);
    expect($job->cluster->id)->toBe($cluster->id);
});
