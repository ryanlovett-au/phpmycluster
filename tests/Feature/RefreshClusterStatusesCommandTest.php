<?php

use App\Jobs\RefreshDbStatusJob;
use App\Models\Cluster;
use App\Models\Node;
use Illuminate\Support\Facades\Bus;

it('outputs a message when there are no active clusters', function () {
    $this->artisan('clusters:refresh-status')
        ->expectsOutput('No active clusters to refresh.')
        ->assertSuccessful();
});

it('dispatches jobs for online clusters', function () {
    Bus::fake();

    Cluster::factory()->online()->create(['name' => 'Online Cluster']);

    $this->artisan('clusters:refresh-status')
        ->assertSuccessful();

    Bus::assertBatched(function ($batch) {
        return str_contains($batch->name, 'Online Cluster');
    });
});

it('dispatches jobs for degraded clusters', function () {
    Bus::fake();

    Cluster::factory()->degraded()->create(['name' => 'Degraded Cluster']);

    $this->artisan('clusters:refresh-status')
        ->assertSuccessful();

    Bus::assertBatched(function ($batch) {
        return str_contains($batch->name, 'Degraded Cluster');
    });
});

it('does not dispatch jobs for offline clusters', function () {
    Bus::fake();

    Cluster::factory()->offline()->create(['name' => 'Offline Cluster']);

    $this->artisan('clusters:refresh-status')
        ->expectsOutput('No active clusters to refresh.')
        ->assertSuccessful();

    Bus::assertNothingBatched();
});

it('does not dispatch jobs for pending clusters', function () {
    Bus::fake();

    Cluster::factory()->create(['name' => 'Pending Cluster']);

    $this->artisan('clusters:refresh-status')
        ->expectsOutput('No active clusters to refresh.')
        ->assertSuccessful();

    Bus::assertNothingBatched();
});

it('includes router status jobs for clusters with access nodes', function () {
    Bus::fake();

    $cluster = Cluster::factory()->online()->create(['name' => 'Cluster With Router']);
    Node::factory()->access()->create([
        'cluster_id' => $cluster->id,
        'name' => 'router-node-1',
    ]);

    $this->artisan('clusters:refresh-status')
        ->assertSuccessful();

    Bus::assertBatched(function ($batch) {
        return str_contains($batch->name, 'Cluster With Router')
            && count($batch->jobs) >= 3; // RefreshDbStatusJob + RefreshRouterStatusJob + RefreshUserListJob
    });
});

it('includes multiple router jobs for clusters with multiple access nodes', function () {
    Bus::fake();

    $cluster = Cluster::factory()->online()->create(['name' => 'Multi Router Cluster']);
    Node::factory()->access()->count(3)->create([
        'cluster_id' => $cluster->id,
    ]);

    $this->artisan('clusters:refresh-status')
        ->assertSuccessful();

    Bus::assertBatched(function ($batch) {
        // 1 RefreshDbStatusJob + 3 RefreshRouterStatusJob + 1 RefreshUserListJob = 5
        return str_contains($batch->name, 'Multi Router Cluster')
            && count($batch->jobs) === 5;
    });
});
