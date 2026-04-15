<?php

use App\Jobs\RefreshDbStatusJob;
use App\Livewire\Dashboard;
use App\Models\MysqlCluster;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

it('allows an approved user to view the dashboard', function () {
    $user = createApprovedUser();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();
});

it('redirects an unapproved user away from the dashboard', function () {
    $user = createPendingUser();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('approval.pending'));
});

it('displays cluster cards on the dashboard', function () {
    $user = createApprovedUser();
    $cluster = MysqlCluster::factory()->online()->create(['name' => 'My Test Cluster']);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertSee('My Test Cluster');
});

it('dispatches a batch of refresh jobs when refreshAll is called', function () {
    Bus::fake();

    $user = createApprovedUser();
    MysqlCluster::factory()->online()->create();

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->call('refreshAll')
        ->assertSet('refreshing', true);

    Bus::assertBatched(fn ($batch) => $batch->jobs->contains(fn ($job) => $job instanceof RefreshDbStatusJob));
});

it('shows a message when refreshAll is called with no active clusters', function () {
    $user = createApprovedUser();

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->call('refreshAll')
        ->assertSet('refreshing', false)
        ->assertSet('refreshMessage', 'No active clusters to refresh.');
});

it('redirects guests to the login page', function () {
    $this->get(route('dashboard'))
        ->assertRedirect(route('login'));
});
