<?php

use App\Tools\Security\AuditReport;
use Illuminate\Console\Command;

it('creates a finding with all fields', function () {
    $finding = AuditReport::finding(
        'high',
        'Authentication',
        'app/Http/Controllers/AuthController.php',
        42,
        'Plaintext password storage',
        'Use bcrypt or argon2 hashing'
    );

    expect($finding)->toBe([
        'severity' => 'high',
        'category' => 'Authentication',
        'file' => 'app/Http/Controllers/AuthController.php',
        'line' => 42,
        'description' => 'Plaintext password storage',
        'remediation' => 'Use bcrypt or argon2 hashing',
    ]);
});

it('filter with all returns all findings', function () {
    $findings = [
        AuditReport::finding('high', 'Auth', 'file.php', 1, 'desc1'),
        AuditReport::finding('low', 'Config', 'file2.php', 2, 'desc2'),
        AuditReport::finding('critical', 'Crypto', 'file3.php', 3, 'desc3'),
    ];

    $filtered = AuditReport::filter($findings, 'all');

    expect($filtered)->toHaveCount(3);
});

it('filter with specific severity returns only matching findings', function () {
    $findings = [
        AuditReport::finding('high', 'Auth', 'file.php', 1, 'desc1'),
        AuditReport::finding('low', 'Config', 'file2.php', 2, 'desc2'),
        AuditReport::finding('high', 'Crypto', 'file3.php', 3, 'desc3'),
    ];

    $filtered = AuditReport::filter($findings, 'high');

    expect($filtered)->toHaveCount(2);
    expect($filtered[0]['severity'])->toBe('high');
    expect($filtered[1]['severity'])->toBe('high');
});

it('sort orders by severity with critical first and info last', function () {
    $findings = [
        AuditReport::finding('info', 'Info', 'a.php', 1, 'info finding'),
        AuditReport::finding('critical', 'Crit', 'b.php', 2, 'critical finding'),
        AuditReport::finding('medium', 'Med', 'c.php', 3, 'medium finding'),
        AuditReport::finding('low', 'Low', 'd.php', 4, 'low finding'),
        AuditReport::finding('high', 'High', 'e.php', 5, 'high finding'),
    ];

    $sorted = AuditReport::sort($findings);

    expect($sorted[0]['severity'])->toBe('critical');
    expect($sorted[1]['severity'])->toBe('high');
    expect($sorted[2]['severity'])->toBe('medium');
    expect($sorted[3]['severity'])->toBe('low');
    expect($sorted[4]['severity'])->toBe('info');
});

it('summary counts findings correctly by severity', function () {
    $findings = [
        AuditReport::finding('critical', 'A', 'a.php', 1, 'd1'),
        AuditReport::finding('critical', 'B', 'b.php', 2, 'd2'),
        AuditReport::finding('high', 'C', 'c.php', 3, 'd3'),
        AuditReport::finding('medium', 'D', 'd.php', 4, 'd4'),
        AuditReport::finding('low', 'E', 'e.php', 5, 'd5'),
        AuditReport::finding('info', 'F', 'f.php', 6, 'd6'),
        AuditReport::finding('info', 'G', 'g.php', 7, 'd7'),
    ];

    $summary = AuditReport::summary($findings);

    expect($summary)->toBe([
        'critical' => 2,
        'high' => 1,
        'medium' => 1,
        'low' => 1,
        'info' => 2,
        'total' => 7,
    ]);
});

it('render_table displays no findings message when empty', function () {
    $command = Mockery::mock(Command::class);
    $command->shouldReceive('info')->once()->with('No findings to display.');

    AuditReport::render_table($command, []);
});

it('render_table displays findings grouped by severity', function () {
    $findings = [
        AuditReport::finding('critical', 'Auth', 'file.php', 1, 'Critical issue'),
        AuditReport::finding('high', 'Config', 'file2.php', 2, 'High issue'),
        AuditReport::finding('medium', 'XSS', 'file3.php', 3, 'Medium issue'),
    ];

    $command = Mockery::mock(Command::class);
    $command->shouldReceive('newLine')->times(3);
    $command->shouldReceive('error')->once(); // critical header
    $command->shouldReceive('warn')->once(); // high header
    $command->shouldReceive('info')->once(); // medium header
    $command->shouldReceive('table')->times(3);

    AuditReport::render_table($command, $findings);
});

it('render_table includes remediation column when show_fix is true', function () {
    $findings = [
        AuditReport::finding('high', 'Auth', 'file.php', 1, 'Issue', 'Fix it'),
    ];

    $command = Mockery::mock(Command::class);
    $command->shouldReceive('newLine')->once();
    $command->shouldReceive('warn')->once();
    $command->shouldReceive('table')->once()->withArgs(function ($headers, $rows) {
        return in_array('Remediation', $headers) && $rows[0][4] === 'Fix it';
    });

    AuditReport::render_table($command, $findings, 'all', true);
});

it('render_table handles line 0 as dash', function () {
    $findings = [
        AuditReport::finding('low', 'Config', 'file.php', 0, 'No line info'),
    ];

    $command = Mockery::mock(Command::class);
    $command->shouldReceive('newLine')->once();
    $command->shouldReceive('info')->once();
    $command->shouldReceive('table')->once()->withArgs(function ($headers, $rows) {
        return $rows[0][2] === '-';
    });

    AuditReport::render_table($command, $findings);
});

it('render_summary outputs counts for all severities', function () {
    $findings = [
        AuditReport::finding('critical', 'A', 'a.php', 1, 'd1'),
        AuditReport::finding('high', 'B', 'b.php', 2, 'd2'),
        AuditReport::finding('medium', 'C', 'c.php', 3, 'd3'),
        AuditReport::finding('low', 'D', 'd.php', 4, 'd4'),
        AuditReport::finding('info', 'E', 'e.php', 5, 'd5'),
    ];

    $command = Mockery::mock(Command::class);
    $command->shouldReceive('newLine')->times(4);
    $command->shouldReceive('line')->atLeast()->once();
    $command->shouldReceive('error')->once(); // CRITICAL line
    $command->shouldReceive('warn')->once(); // HIGH line
    $command->shouldReceive('info')->once(); // MEDIUM line

    AuditReport::render_summary($command, $findings);
});

it('render_json returns valid JSON with summary and findings', function () {
    $findings = [
        AuditReport::finding('high', 'Auth', 'file.php', 10, 'issue'),
        AuditReport::finding('low', 'Config', 'file2.php', 20, 'minor issue'),
    ];

    $json = AuditReport::render_json($findings);
    $decoded = json_decode($json, true);

    expect($decoded)->not->toBeNull();
    expect($decoded)->toHaveKeys(['summary', 'findings']);
    expect($decoded['summary'])->toHaveKeys(['critical', 'high', 'medium', 'low', 'info', 'total']);
    expect($decoded['summary']['total'])->toBe(2);
    expect($decoded['summary']['high'])->toBe(1);
    expect($decoded['summary']['low'])->toBe(1);
    expect($decoded['findings'])->toHaveCount(2);
    // Should be sorted: high first, then low
    expect($decoded['findings'][0]['severity'])->toBe('high');
    expect($decoded['findings'][1]['severity'])->toBe('low');
});
