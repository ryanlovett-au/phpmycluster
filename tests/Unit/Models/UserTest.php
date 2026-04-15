<?php

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses HasFactory trait', function () {
    expect(in_array(HasFactory::class, class_uses_recursive(User::class)))->toBeTrue();
});

it('returns true from isApproved() when approved_at is set', function () {
    $user = User::factory()->create(['approved_at' => now()]);

    expect($user->isApproved())->toBeTrue();
});

it('returns false from isApproved() when approved_at is null', function () {
    $user = User::factory()->create(['approved_at' => null]);

    expect($user->isApproved())->toBeFalse();
});

it('sets approved_at and approved_by when approve() is called', function () {
    $admin = User::factory()->create(['approved_at' => now(), 'is_admin' => true]);
    $user = User::factory()->create(['approved_at' => null]);

    $user->approve($admin);

    $user->refresh();
    expect($user->approved_at)->not->toBeNull()
        ->and($user->approved_by)->toBe($admin->id);
});

it('returns the approving user via approver()', function () {
    $admin = User::factory()->create(['approved_at' => now(), 'is_admin' => true]);
    $user = User::factory()->create(['approved_at' => null]);

    $user->approve($admin);
    $user->refresh();

    expect($user->approver())->toBeInstanceOf(BelongsTo::class)
        ->and($user->approver->id)->toBe($admin->id);
});

it('returns correct initials for two-word name', function () {
    $user = User::factory()->make(['name' => 'John Doe']);

    expect($user->initials())->toBe('JD');
});

it('returns correct initials for single-word name', function () {
    $user = User::factory()->make(['name' => 'Madonna']);

    expect($user->initials())->toBe('M');
});

it('returns correct initials for three-word name (takes first two)', function () {
    $user = User::factory()->make(['name' => 'Mary Jane Watson']);

    expect($user->initials())->toBe('MJ');
});
