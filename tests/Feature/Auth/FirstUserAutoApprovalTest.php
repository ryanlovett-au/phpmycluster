<?php

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;

it('auto-approves and makes admin the first registered user', function () {
    expect(User::count())->toBe(0);

    $action = new CreateNewUser;
    $user = $action->create([
        'name' => 'First User',
        'email' => 'first@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    expect($user->isApproved())->toBeTrue();
    expect($user->is_admin)->toBeTrue();
});

it('does not auto-approve the second registered user', function () {
    // Create the first user (auto-approved)
    $action = new CreateNewUser;
    $first = $action->create([
        'name' => 'First User',
        'email' => 'first@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    // Create the second user
    $second = $action->create([
        'name' => 'Second User',
        'email' => 'second@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    expect($second->isApproved())->toBeFalse();
    expect($second->is_admin)->toBeFalsy();
});
