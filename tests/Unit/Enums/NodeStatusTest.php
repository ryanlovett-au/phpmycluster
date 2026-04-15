<?php

use App\Enums\NodeStatus;

it('has all expected values', function () {
    $cases = array_map(fn ($case) => $case->value, NodeStatus::cases());

    expect($cases)->toContain('unknown');
    expect($cases)->toContain('online');
    expect($cases)->toContain('recovering');
    expect($cases)->toContain('offline');
    expect($cases)->toContain('error');
    expect($cases)->toContain('unreachable');
    expect($cases)->toHaveCount(6);
});
