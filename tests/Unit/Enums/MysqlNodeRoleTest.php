<?php

use App\Enums\MysqlNodeRole;

it('has all expected values', function () {
    $cases = array_map(fn ($case) => $case->value, MysqlNodeRole::cases());

    expect($cases)->toContain('pending');
    expect($cases)->toContain('primary');
    expect($cases)->toContain('secondary');
    expect($cases)->toContain('access');
    expect($cases)->toHaveCount(4);
});
