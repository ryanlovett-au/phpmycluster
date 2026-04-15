<?php

use App\Enums\NodeRole;

it('has all expected values', function () {
    $cases = array_map(fn ($case) => $case->value, NodeRole::cases());

    expect($cases)->toContain('pending');
    expect($cases)->toContain('primary');
    expect($cases)->toContain('secondary');
    expect($cases)->toContain('access');
    expect($cases)->toHaveCount(4);
});
