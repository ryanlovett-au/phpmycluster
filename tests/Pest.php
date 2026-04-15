<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

/**
 * Create and return an approved admin user.
 */
function createAdmin(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'approved_at' => now(),
        'is_admin' => true,
    ], $attributes));
}

/**
 * Create and return an approved (non-admin) user.
 */
function createApprovedUser(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'approved_at' => now(),
        'is_admin' => false,
    ], $attributes));
}

/**
 * Create and return a pending (unapproved) user.
 */
function createPendingUser(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'approved_at' => null,
        'is_admin' => false,
    ], $attributes));
}
