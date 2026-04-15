<?php

namespace App\Tools\Security;

use Illuminate\Console\Command;

class AuditReport
{
    /**
     * Severity levels and their display properties.
     */
    private static array $severities = [
        'critical' => ['order' => 0, 'label' => 'CRITICAL'],
        'high' => ['order' => 1, 'label' => 'HIGH'],
        'medium' => ['order' => 2, 'label' => 'MEDIUM'],
        'low' => ['order' => 3, 'label' => 'LOW'],
        'info' => ['order' => 4, 'label' => 'INFO'],
    ];

    /**
     * Create a structured finding.
     */
    public static function finding(
        string $severity,
        string $category,
        string $file,
        int $line,
        string $description,
        string $remediation = ''
    ): array {
        return [
            'severity' => $severity,
            'category' => $category,
            'file' => $file,
            'line' => $line,
            'description' => $description,
            'remediation' => $remediation,
        ];
    }

    /**
     * Filter findings by severity level.
     */
    public static function filter(array $findings, string $severity_filter): array
    {
        if ($severity_filter === 'all') {
            return $findings;
        }

        return array_values(array_filter($findings, function ($f) use ($severity_filter) {
            return $f['severity'] === $severity_filter;
        }));
    }

    /**
     * Sort findings by severity (critical first).
     */
    public static function sort(array $findings): array
    {
        usort($findings, function ($a, $b) {
            $order_a = self::$severities[$a['severity']]['order'] ?? 99;
            $order_b = self::$severities[$b['severity']]['order'] ?? 99;

            return $order_a <=> $order_b;
        });

        return $findings;
    }

    /**
     * Render findings as a table to the console.
     */
    public static function render_table(Command $command, array $findings, string $severity_filter = 'all', bool $show_fix = false): void
    {
        $findings = self::filter($findings, $severity_filter);
        $findings = self::sort($findings);

        if (empty($findings)) {
            $command->info('No findings to display.');

            return;
        }

        // Group by severity
        $grouped = [];
        foreach ($findings as $finding) {
            $grouped[$finding['severity']][] = $finding;
        }

        foreach (self::$severities as $severity => $props) {
            if (! isset($grouped[$severity])) {
                continue;
            }

            $command->newLine();

            // Severity header
            if ($severity === 'critical') {
                $command->error(' '.$props['label'].' ('.count($grouped[$severity]).' findings) ');
            } elseif ($severity === 'high') {
                $command->warn(' '.$props['label'].' ('.count($grouped[$severity]).' findings) ');
            } else {
                $command->info(' '.$props['label'].' ('.count($grouped[$severity]).' findings) ');
            }

            // Build table rows
            $headers = ['Category', 'File', 'Line', 'Description'];
            if ($show_fix) {
                $headers[] = 'Remediation';
            }

            $rows = [];
            foreach ($grouped[$severity] as $finding) {
                $row = [
                    $finding['category'],
                    self::shorten_path($finding['file']),
                    $finding['line'] > 0 ? $finding['line'] : '-',
                    $finding['description'],
                ];
                if ($show_fix) {
                    $row[] = $finding['remediation'];
                }
                $rows[] = $row;
            }

            $command->table($headers, $rows);
        }
    }

    /**
     * Render findings as JSON.
     */
    public static function render_json(array $findings, string $severity_filter = 'all'): string
    {
        $findings = self::filter($findings, $severity_filter);
        $findings = self::sort($findings);

        return json_encode([
            'summary' => self::summary($findings),
            'findings' => $findings,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Output a summary of findings to the console.
     */
    public static function render_summary(Command $command, array $findings): void
    {
        $summary = self::summary($findings);

        $command->newLine();
        $command->line('=== Security Audit Summary ===');
        $command->newLine();

        if ($summary['critical'] > 0) {
            $command->error('  CRITICAL: '.$summary['critical']);
        }
        if ($summary['high'] > 0) {
            $command->warn('  HIGH:     '.$summary['high']);
        }
        if ($summary['medium'] > 0) {
            $command->info('  MEDIUM:   '.$summary['medium']);
        }
        if ($summary['low'] > 0) {
            $command->line('  LOW:      '.$summary['low']);
        }
        if ($summary['info'] > 0) {
            $command->line('  INFO:     '.$summary['info']);
        }

        $command->newLine();
        $command->line('  TOTAL:    '.$summary['total']);
        $command->newLine();
    }

    /**
     * Generate summary counts by severity.
     */
    public static function summary(array $findings): array
    {
        $counts = [
            'critical' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
            'info' => 0,
            'total' => count($findings),
        ];

        foreach ($findings as $finding) {
            if (isset($counts[$finding['severity']])) {
                $counts[$finding['severity']]++;
            }
        }

        return $counts;
    }

    /**
     * Shorten a file path for display by removing the base app path.
     */
    private static function shorten_path(string $path): string
    {
        $base = base_path().'/';

        if (str_starts_with($path, $base)) {
            return substr($path, strlen($base));
        }

        return $path;
    }
}
