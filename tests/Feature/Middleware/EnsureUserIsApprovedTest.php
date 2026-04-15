<?php

it('allows approved users to access protected routes', function () {
    $user = createApprovedUser();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();
});

it('redirects unapproved users to the approval pending page', function () {
    $user = createPendingUser();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('approval.pending'));
});

it('redirects guests to the login page', function () {
    $this->get(route('dashboard'))
        ->assertRedirect(route('login'));
});

it('allows approved admins to access protected routes', function () {
    $admin = createAdmin();

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk();
});

it('redirects unapproved users from all protected routes', function () {
    $user = createPendingUser();

    $this->actingAs($user)
        ->get(route('users.index'))
        ->assertRedirect(route('approval.pending'));
});
