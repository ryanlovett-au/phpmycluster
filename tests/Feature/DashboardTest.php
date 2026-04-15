<?php

use App\Livewire\Dashboard;
use App\Models\MysqlCluster;
use Illuminate\Support\Facades\Artisan;
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

it('dispatches the refresh status command when refreshAll is called', function () {
    Artisan::shouldReceive('call')
        ->once()
        ->with('clusters:refresh-status');

    $user = createApprovedUser();

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->call('refreshAll')
        ->assertSet('refreshMessage', 'Refresh jobs dispatched for all active clusters.');
});

it('redirects guests to the login page', function () {
    $this->get(route('dashboard'))
        ->assertRedirect(route('login'));
});
