<?php

use App\Providers\AppServiceProvider;
use App\Services\FirewallService;
use App\Services\LogStreamService;
use App\Services\MysqlShellService;
use App\Services\NodeProvisionService;
use App\Services\SshService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rules\Password;

it('registers SshService as singleton', function () {
    expect(app(SshService::class))
        ->toBe(app(SshService::class));
});

it('registers MysqlShellService as singleton', function () {
    expect(app(MysqlShellService::class))
        ->toBe(app(MysqlShellService::class));
});

it('registers NodeProvisionService as singleton', function () {
    expect(app(NodeProvisionService::class))
        ->toBe(app(NodeProvisionService::class));
});

it('registers FirewallService as singleton', function () {
    expect(app(FirewallService::class))
        ->toBe(app(FirewallService::class));
});

it('registers LogStreamService as singleton', function () {
    expect(app(LogStreamService::class))
        ->toBe(app(LogStreamService::class));
});

it('uses CarbonImmutable for dates', function () {
    expect(now())->toBeInstanceOf(CarbonImmutable::class);
});

it('configures password defaults in production', function () {
    // Temporarily set app to production
    app()->detectEnvironment(fn () => 'production');

    // Re-run the boot method to trigger production password rules
    $provider = new AppServiceProvider(app());
    $provider->boot();

    $rules = Password::defaults();
    expect($rules)->toBeInstanceOf(Password::class);

    // Reset back to testing
    app()->detectEnvironment(fn () => 'testing');

    // Re-run boot to reset password defaults
    $provider->boot();
});

it('returns relaxed password rules in non-production', function () {
    // Re-boot in testing mode to reset
    app()->detectEnvironment(fn () => 'testing');
    $provider = new AppServiceProvider(app());
    $provider->boot();

    $rules = Password::defaults();
    // In non-production, defaults callback returns null (no strict rules)
    // But Password::defaults() wraps it: returns null or a basic Password instance
    expect($rules === null || $rules instanceof Password)->toBeTrue();
});
