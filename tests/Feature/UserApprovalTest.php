<?php

use App\Livewire\UserApproval;
use App\Models\User;
use Livewire\Livewire;

it('allows an admin to view the user approval page', function () {
    $admin = createAdmin();

    Livewire::actingAs($admin)
        ->test(UserApproval::class)
        ->assertOk();
});

it('forbids a non-admin from viewing the user approval page', function () {
    $user = createApprovedUser();

    Livewire::actingAs($user)
        ->test(UserApproval::class)
        ->assertForbidden();
});

it('allows an admin to approve a pending user', function () {
    $admin = createAdmin();
    $pending = createPendingUser();

    Livewire::actingAs($admin)
        ->test(UserApproval::class)
        ->call('approve', $pending->id)
        ->assertSet('actionStatus', 'success');

    $pending->refresh();
    expect($pending->isApproved())->toBeTrue();
    expect($pending->approved_by)->toBe($admin->id);
});

it('allows an admin to revoke an approved user', function () {
    $admin = createAdmin();
    $approved = createApprovedUser();

    Livewire::actingAs($admin)
        ->test(UserApproval::class)
        ->call('revoke', $approved->id)
        ->assertSet('actionStatus', 'success');

    $approved->refresh();
    expect($approved->isApproved())->toBeFalse();
});

it('allows an admin to toggle admin status of another user', function () {
    $admin = createAdmin();
    $user = createApprovedUser();

    expect($user->is_admin)->toBeFalse();

    Livewire::actingAs($admin)
        ->test(UserApproval::class)
        ->call('toggleAdmin', $user->id)
        ->assertSet('actionStatus', 'success');

    $user->refresh();
    expect($user->is_admin)->toBeTrue();

    // Toggle back off
    Livewire::actingAs($admin)
        ->test(UserApproval::class)
        ->call('toggleAdmin', $user->id)
        ->assertSet('actionStatus', 'success');

    $user->refresh();
    expect($user->is_admin)->toBeFalse();
});

it('allows an admin to delete a user', function () {
    $admin = createAdmin();
    $user = createApprovedUser();
    $userId = $user->id;

    Livewire::actingAs($admin)
        ->test(UserApproval::class)
        ->call('deleteUser', $userId)
        ->assertSet('actionStatus', 'success');

    expect(User::find($userId))->toBeNull();
});

it('prevents an admin from deleting themselves', function () {
    $admin = createAdmin();

    Livewire::actingAs($admin)
        ->test(UserApproval::class)
        ->call('deleteUser', $admin->id)
        ->assertSet('actionStatus', 'error')
        ->assertSet('actionMessage', 'You cannot delete your own account.');

    expect(User::find($admin->id))->not->toBeNull();
});

it('prevents an admin from revoking their own approval', function () {
    $admin = createAdmin();

    Livewire::actingAs($admin)
        ->test(UserApproval::class)
        ->call('revoke', $admin->id)
        ->assertSet('actionStatus', 'error')
        ->assertSet('actionMessage', 'You cannot revoke your own approval.');

    $admin->refresh();
    expect($admin->isApproved())->toBeTrue();
});

it('prevents an admin from toggling their own admin status', function () {
    $admin = createAdmin();

    Livewire::actingAs($admin)
        ->test(UserApproval::class)
        ->call('toggleAdmin', $admin->id)
        ->assertSet('actionStatus', 'error')
        ->assertSet('actionMessage', 'You cannot change your own admin status.');

    $admin->refresh();
    expect($admin->is_admin)->toBeTrue();
});

it('forbids a non-admin from approving users', function () {
    $user = createApprovedUser();
    $pending = createPendingUser();

    Livewire::actingAs($user)
        ->test(UserApproval::class)
        ->assertForbidden();
});

it('shows info message when approving an already approved user', function () {
    $admin = createAdmin();
    $approved = createApprovedUser();

    Livewire::actingAs($admin)
        ->test(UserApproval::class)
        ->call('approve', $approved->id)
        ->assertSet('actionStatus', 'info')
        ->assertSet('actionMessage', "{$approved->name} is already approved.");
});

it('renders pending and approved users in the view', function () {
    $admin = createAdmin();
    $pending = createPendingUser(['name' => 'Pending Person']);
    $approved = createApprovedUser(['name' => 'Approved Person']);

    Livewire::actingAs($admin)
        ->test(UserApproval::class)
        ->assertSee('Pending Person')
        ->assertSee('Approved Person');
});
