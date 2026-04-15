<?php

use App\Enums\ClusterStatus;

it('has all expected values', function () {
    $cases = array_map(fn ($case) => $case->value, ClusterStatus::cases());

    expect($cases)->toContain('pending');
    expect($cases)->toContain('online');
    expect($cases)->toContain('degraded');
    expect($cases)->toContain('offline');
    expect($cases)->toContain('error');
    expect($cases)->toHaveCount(5);
});

it('can be created from string values', function () {
    expect(ClusterStatus::from('pending'))->toBe(ClusterStatus::Pending);
    expect(ClusterStatus::from('online'))->toBe(ClusterStatus::Online);
    expect(ClusterStatus::from('degraded'))->toBe(ClusterStatus::Degraded);
    expect(ClusterStatus::from('offline'))->toBe(ClusterStatus::Offline);
    expect(ClusterStatus::from('error'))->toBe(ClusterStatus::Error);
});
