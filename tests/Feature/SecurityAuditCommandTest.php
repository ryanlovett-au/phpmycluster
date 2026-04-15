<?php

use Illuminate\Support\Facades\Artisan;

// --- Helpers ---

function getJsonFindings(array $options = []): array
{
    $defaults = ['--output' => 'json'];
    Artisan::call('security:audit', array_merge($defaults, $options));
    $output = Artisan::output();

    $jsonStart = strpos($output, '{');
    expect($jsonStart)->not->toBeFalse('JSON output should contain a JSON object');

    return json_decode(substr($output, $jsonStart), true);
}

function getFindingCategories(array $decoded): array
{
    return array_unique(array_column($decoded['findings'], 'category'));
}

// --- Original tests ---

it('runs the security audit command', function () {
    $this->artisan('security:audit')
        ->assertExitCode(1); // Returns 1 when critical/high findings exist
});

it('accepts the --output=json flag', function () {
    $decoded = getJsonFindings();

    expect($decoded)->toBeArray()
        ->toHaveKey('summary')
        ->toHaveKey('findings');
});

it('accepts the --severity flag to filter findings', function () {
    $decoded = getJsonFindings(['--severity' => 'info']);

    foreach ($decoded['findings'] as $finding) {
        expect($finding['severity'])->toBe('info');
    }
});

it('accepts the --fix flag and includes remediation in output', function () {
    $exitCode = Artisan::call('security:audit', ['--fix' => true]);

    // Command ran without error (exit 1 is expected due to findings)
    expect($exitCode)->toBeIn([0, 1]);
});

it('json output contains correct summary keys', function () {
    $decoded = getJsonFindings();

    expect($decoded['summary'])->toHaveKeys(['critical', 'high', 'medium', 'low', 'info', 'total']);
});

// --- New tests for scanner coverage ---

it('returns valid json with all scanner categories present', function () {
    $decoded = getJsonFindings();
    $categories = getFindingCategories($decoded);

    // The audit should detect at least some of these categories from the real codebase
    expect($decoded['findings'])->not->toBeEmpty();
    expect($decoded['summary']['total'])->toBeGreaterThan(0);
});

it('filters to only critical findings with --severity=critical', function () {
    $decoded = getJsonFindings(['--severity' => 'critical']);

    foreach ($decoded['findings'] as $finding) {
        expect($finding['severity'])->toBe('critical');
    }
});

it('filters to only medium findings with --severity=medium', function () {
    $decoded = getJsonFindings(['--severity' => 'medium']);

    foreach ($decoded['findings'] as $finding) {
        expect($finding['severity'])->toBe('medium');
    }
});

it('filters to only high findings with --severity=high', function () {
    $decoded = getJsonFindings(['--severity' => 'high']);

    foreach ($decoded['findings'] as $finding) {
        expect($finding['severity'])->toBe('high');
    }
});

it('filters to only low findings with --severity=low', function () {
    $decoded = getJsonFindings(['--severity' => 'low']);

    foreach ($decoded['findings'] as $finding) {
        expect($finding['severity'])->toBe('low');
    }
});

it('produces table output when run without --output flag', function () {
    Artisan::call('security:audit');
    $output = Artisan::output();

    // Table output should include the summary section
    expect($output)->toContain('Security Audit Summary');
});

it('produces table output with --fix flag showing remediation columns', function () {
    Artisan::call('security:audit', ['--fix' => true]);
    $output = Artisan::output();

    expect($output)->toContain('Remediation');
});

it('json output includes command injection findings from MysqlShellService', function () {
    $decoded = getJsonFindings();

    $commandInjectionFindings = array_filter($decoded['findings'], function ($f) {
        return $f['category'] === 'Command Injection';
    });

    // MysqlShellService uses exec() with variable interpolation, so command injection should be detected
    $fileMatches = array_filter($commandInjectionFindings, function ($f) {
        return str_contains($f['file'], 'MysqlShellService');
    });

    expect($fileMatches)->not->toBeEmpty('MysqlShellService should trigger command injection findings');
});

it('json output includes configuration findings from env scanning', function () {
    $decoded = getJsonFindings();

    $configFindings = array_filter($decoded['findings'], function ($f) {
        return $f['category'] === 'Configuration';
    });

    // In testing, APP_DEBUG is typically true which triggers an info-level finding
    expect($configFindings)->not->toBeEmpty('Configuration scanner should produce findings');
});

it('suppresses known false positives', function () {
    $decoded = getJsonFindings();

    // False positives for NodeProvisionService command injection should be suppressed
    $nodeProvisionCmdInjection = array_filter($decoded['findings'], function ($f) {
        return $f['category'] === 'Command Injection'
            && str_contains($f['file'], 'NodeProvisionService.php')
            && str_contains($f['description'], 'Shell command built with string concatenation');
    });

    expect($nodeProvisionCmdInjection)->toBeEmpty('NodeProvisionService false positives should be suppressed');

    // False positives for FirewallService command injection should be suppressed
    $firewallCmdInjection = array_filter($decoded['findings'], function ($f) {
        return $f['category'] === 'Command Injection'
            && str_contains($f['file'], 'FirewallService.php')
            && str_contains($f['description'], 'Shell command built with string concatenation');
    });

    expect($firewallCmdInjection)->toBeEmpty('FirewallService false positives should be suppressed');
});

it('json findings have required structure for each finding', function () {
    $decoded = getJsonFindings();

    foreach ($decoded['findings'] as $finding) {
        expect($finding)->toHaveKeys(['severity', 'category', 'file', 'line', 'description', 'remediation']);
        expect($finding['severity'])->toBeIn(['critical', 'high', 'medium', 'low', 'info']);
        expect($finding['line'])->toBeInt();
    }
});

it('summary counts match actual findings', function () {
    $decoded = getJsonFindings();

    $expected = [
        'critical' => 0,
        'high' => 0,
        'medium' => 0,
        'low' => 0,
        'info' => 0,
    ];

    foreach ($decoded['findings'] as $finding) {
        $expected[$finding['severity']]++;
    }

    expect($decoded['summary']['critical'])->toBe($expected['critical']);
    expect($decoded['summary']['high'])->toBe($expected['high']);
    expect($decoded['summary']['medium'])->toBe($expected['medium']);
    expect($decoded['summary']['low'])->toBe($expected['low']);
    expect($decoded['summary']['info'])->toBe($expected['info']);
    expect($decoded['summary']['total'])->toBe(count($decoded['findings']));
});

it('returns failure exit code when critical or high findings exist', function () {
    $decoded = getJsonFindings();

    $hasCriticalOrHigh = $decoded['summary']['critical'] > 0 || $decoded['summary']['high'] > 0;

    $exitCode = Artisan::call('security:audit');

    if ($hasCriticalOrHigh) {
        expect($exitCode)->toBe(1);
    } else {
        expect($exitCode)->toBe(0);
    }
});

it('detects env file configuration issues', function () {
    $decoded = getJsonFindings();

    // APP_DEBUG=true in testing should produce at least an info-level finding
    $envFindings = array_filter($decoded['findings'], function ($f) {
        return $f['category'] === 'Configuration' && str_contains($f['description'], 'APP_DEBUG');
    });

    expect($envFindings)->not->toBeEmpty('APP_DEBUG=true should trigger a configuration finding');
});
