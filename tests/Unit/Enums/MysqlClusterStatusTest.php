<?php

use App\Enums\MysqlClusterStatus;

it('has all expected values', function () {
    $cases = array_map(fn ($case) => $case->value, MysqlClusterStatus::cases());

    expect($cases)->toContain('pending');
    expect($cases)->toContain('online');
    expect($cases)->toContain('degraded');
    expect($cases)->toContain('offline');
    expect($cases)->toContain('error');
    expect($cases)->toHaveCount(5);
});

it('can be created from string values', function () {
    expect(MysqlClusterStatus::from('pending'))->toBe(MysqlClusterStatus::Pending);
    expect(MysqlClusterStatus::from('online'))->toBe(MysqlClusterStatus::Online);
    expect(MysqlClusterStatus::from('degraded'))->toBe(MysqlClusterStatus::Degraded);
    expect(MysqlClusterStatus::from('offline'))->toBe(MysqlClusterStatus::Offline);
    expect(MysqlClusterStatus::from('error'))->toBe(MysqlClusterStatus::Error);
});
