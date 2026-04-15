<?php

use App\Jobs\RefreshUserListJob;
use App\Models\Cluster;
use Illuminate\Bus\Batchable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses the Batchable trait', function () {
    $traits = class_uses_recursive(RefreshUserListJob::class);
    expect($traits)->toContain(Batchable::class);
});

it('has a timeout of 30 seconds', function () {
    $cluster = Cluster::factory()->create();
    $job = new RefreshUserListJob($cluster);

    expect($job->timeout)->toBe(30);
});

it('has tries set to 1', function () {
    $cluster = Cluster::factory()->create();
    $job = new RefreshUserListJob($cluster);

    expect($job->tries)->toBe(1);
});

it('accepts a Cluster model in the constructor', function () {
    $cluster = Cluster::factory()->create();
    $job = new RefreshUserListJob($cluster);

    expect($job->cluster)->toBeInstanceOf(Cluster::class);
    expect($job->cluster->id)->toBe($cluster->id);
});
