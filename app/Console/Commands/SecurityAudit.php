<?php

namespace App\Console\Commands;

use App\Tools\Security\AuditReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * @codeCoverageIgnore
 */
class SecurityAudit extends Command
{
    protected $signature = 'security:audit
        {--output=table : Output format (table, json)}
        {--severity=all : Filter by severity (critical, high, medium, low, info, all)}
        {--fix : Show remediation suggestions}';

    protected $description = 'Run a static security audit of the PHPMyCluster codebase';

    /**
     * Known false positives that should be excluded from results.
     * Each entry is [category, file_path_suffix, description_substring, reason].
     */
    protected array $false_positives = [
        // SSH commands that are safely constructed (no user input in command string)
        ['Command Injection', 'MysqlProvisionService.php', 'Shell command built with string concatenation', 'Commands use server-side config values only, not user input.'],
        ['Command Injection', 'FirewallService.php', 'Shell command built with string concatenation', 'UFW commands use validated IP addresses only.'],

        // SecurityAudit.php self-referencing: regex patterns contain shell function names as detection strings
        ['Command Injection', 'SecurityAudit.php', 'Shell command built with string concatenation', 'Regex patterns for detection, not actual shell commands.'],
        ['SSH Key Exposure', 'SecurityAudit.php', 'Encrypted SSH key may be exposed', 'Code is checking for exposure, not exposing keys.'],

        // XSS: JSON encoding in blade templates is safe
        ['XSS', 'cluster-manager.blade.php', 'json_encode', 'Server-side JSON encoding of cluster status data.'],

        // XSS: QR code SVG is generated server-side by the 2FA library, not user input
        ['XSS', 'two-factor-setup-modal.blade.php', 'qrCodeSvg', 'Server-generated SVG from 2FA library.'],
    ];

    protected array $findings = [];

    public function handle(): int
    {
        $this->info('Running PHPMyCluster Security Audit...');
        $this->newLine();

        // Run all scanners
        $this->scan_command_injection();
        $this->scan_sql_injection();
        $this->scan_ssh_key_exposure();
        $this->scan_mass_assignment();
        $this->scan_input_validation();
        $this->scan_blade_xss();
        $this->scan_rate_limiting();
        $this->scan_session_config();
        $this->scan_credential_exposure();
        $this->scan_env_file();
        $this->scan_debug_mode();
        $this->scan_encryption_config();

        // Remove false positives
        $this->findings = $this->suppress_false_positives($this->findings);

        // Output results
        $output = $this->option('output');
        $severity = $this->option('severity');
        $show_fix = $this->option('fix');

        if ($output === 'json') {
            $this->line(AuditReport::render_json($this->findings, $severity));
        } else {
            AuditReport::render_table($this, $this->findings, $severity, $show_fix);
            AuditReport::render_summary($this, $this->findings);
        }

        // Return non-zero if critical or high findings exist
        $summary = AuditReport::summary($this->findings);
        if ($summary['critical'] > 0 || $summary['high'] > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Scan for command injection vulnerabilities in SSH commands.
     *
     * PHPMyCluster executes shell commands on remote servers via SSH.
     * Any user input interpolated into these commands is a critical risk.
     *
     * @codeCoverageIgnore Static regex scanner — tested via integration tests on command output.
     */
    protected function scan_command_injection(): void
    {
        $this->info('Scanning for command injection...');

        $scan_paths = [
            app_path('Services'),
            app_path('Jobs'),
            app_path('Livewire'),
            app_path('Console/Commands'),
        ];

        // Patterns that indicate shell command construction
        $dangerous_patterns = [
            '/->exec\s*\(\s*["\'].*\$/' => 'Shell command with variable interpolation in exec()',
            '/->exec\s*\(\s*[^"\']*\..*\$/' => 'Shell command with concatenated variable in exec()',
            '/shell_exec\s*\(/' => 'Direct shell_exec() call',
            '/proc_open\s*\(/' => 'Direct proc_open() call',
            '/passthru\s*\(/' => 'Direct passthru() call',
            '/system\s*\(/' => 'Direct system() call',
            '/`[^`]*\$/' => 'Backtick execution with variable interpolation',
        ];

        // Patterns that indicate safe command construction
        $safe_patterns = [
            'escapeshellarg',
            'escapeshellcmd',
            '<<<',  // Heredoc/nowdoc — typically safe
        ];

        foreach ($scan_paths as $scan_path) {
            if (! is_dir($scan_path)) {
                continue;
            }

            foreach (File::allFiles($scan_path) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $content = $file->getContents();
                $lines = explode("\n", $content);
                $path = $file->getPathname();

                foreach ($lines as $line_num => $line) {
                    foreach ($dangerous_patterns as $pattern => $description) {
                        if (preg_match($pattern, $line)) {
                            // Check if the line also contains safe escaping
                            $is_safe = false;
                            foreach ($safe_patterns as $safe) {
                                if (str_contains($line, $safe)) {
                                    $is_safe = true;
                                    break;
                                }
                            }

                            if (! $is_safe) {
                                $this->findings[] = AuditReport::finding(
                                    'critical',
                                    'Command Injection',
                                    $path,
                                    $line_num + 1,
                                    'Shell command built with string concatenation: '.trim(mb_substr($line, 0, 120)),
                                    'Use escapeshellarg() for all user-supplied values, or use nowdoc for complex commands.'
                                );
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Scan for SQL injection vulnerabilities.
     *
     * @codeCoverageIgnore Static regex scanner — tested via integration tests on command output.
     */
    protected function scan_sql_injection(): void
    {
        $this->info('Scanning for SQL injection...');

        $scan_paths = [
            app_path('Services'),
            app_path('Livewire'),
            app_path('Models'),
            app_path('Jobs'),
        ];

        $dangerous_patterns = [
            '/whereRaw\s*\(\s*["\'].*\$/' => 'Variable interpolation in whereRaw()',
            '/selectRaw\s*\(\s*["\'].*\$/' => 'Variable interpolation in selectRaw()',
            '/DB::raw\s*\(\s*["\'].*\$/' => 'Variable interpolation in DB::raw()',
            '/orderByRaw\s*\(\s*["\'].*\$/' => 'Variable interpolation in orderByRaw()',
            '/DB::statement\s*\(\s*["\'].*\$/' => 'Variable interpolation in DB::statement()',
            '/DB::unprepared\s*\(/' => 'Use of DB::unprepared() — no parameter binding',
        ];

        foreach ($scan_paths as $scan_path) {
            if (! is_dir($scan_path)) {
                continue;
            }

            foreach (File::allFiles($scan_path) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $content = $file->getContents();
                $lines = explode("\n", $content);
                $path = $file->getPathname();

                foreach ($lines as $line_num => $line) {
                    foreach ($dangerous_patterns as $pattern => $description) {
                        if (preg_match($pattern, $line)) {
                            $this->findings[] = AuditReport::finding(
                                'critical',
                                'SQL Injection',
                                $path,
                                $line_num + 1,
                                $description.': '.trim(mb_substr($line, 0, 120)),
                                'Use parameter binding instead of string interpolation.'
                            );
                        }
                    }
                }
            }
        }
    }

    /**
     * Scan for SSH key exposure risks.
     *
     * Ensure private keys are always encrypted at rest and never logged or exposed.
     *
     * @codeCoverageIgnore Static regex scanner — tested via integration tests on command output.
     */
    protected function scan_ssh_key_exposure(): void
    {
        $this->info('Scanning for SSH key exposure...');

        $scan_paths = [
            app_path(),
            resource_path('views'),
        ];

        foreach ($scan_paths as $scan_path) {
            if (! is_dir($scan_path)) {
                continue;
            }

            foreach (File::allFiles($scan_path) as $file) {
                $ext = $file->getExtension();
                if (! in_array($ext, ['php', 'blade.php'])) {
                    continue;
                }

                $content = $file->getContents();
                $lines = explode("\n", $content);
                $path = $file->getPathname();

                foreach ($lines as $line_num => $line) {
                    // Check for logging of SSH keys
                    if (preg_match('/(?:Log::|log|logger|info|debug|error|warning)\s*\(.*(?:private_key|ssh_key|privateKey)/i', $line)) {
                        $this->findings[] = AuditReport::finding(
                            'critical',
                            'SSH Key Exposure',
                            $path,
                            $line_num + 1,
                            'SSH private key may be written to logs.',
                            'Never log SSH private key material.'
                        );
                    }

                    // Check for dd/dump of SSH keys
                    if (preg_match('/(?:dd|dump|var_dump|print_r)\s*\(.*(?:private_key|ssh_key|privateKey)/i', $line)) {
                        $this->findings[] = AuditReport::finding(
                            'critical',
                            'SSH Key Exposure',
                            $path,
                            $line_num + 1,
                            'SSH private key may be exposed via debug output.',
                            'Remove debug statements that output key material.'
                        );
                    }

                    // Check for SSH keys in responses or views
                    if (preg_match('/(?:response|json|return).*ssh_private_key_encrypted/i', $line)) {
                        $this->findings[] = AuditReport::finding(
                            'high',
                            'SSH Key Exposure',
                            $path,
                            $line_num + 1,
                            'Encrypted SSH key may be exposed in response.',
                            'Exclude ssh_private_key_encrypted from API responses using $hidden.'
                        );
                    }
                }
            }
        }

        // Check that the Node model hides the SSH key
        $node_model = app_path('Models/Node.php');
        if (file_exists($node_model)) {
            $content = file_get_contents($node_model);

            // Check for encrypted cast
            if (! str_contains($content, "'ssh_private_key_encrypted' => 'encrypted'")) {
                $this->findings[] = AuditReport::finding(
                    'critical',
                    'SSH Key Exposure',
                    $node_model,
                    0,
                    'SSH private key column is not using the encrypted cast.',
                    "Add 'ssh_private_key_encrypted' => 'encrypted' to the \$casts array."
                );
            }

            // Check for $hidden
            if (! str_contains($content, 'ssh_private_key_encrypted') || ! str_contains($content, '$hidden')) {
                $this->findings[] = AuditReport::finding(
                    'medium',
                    'SSH Key Exposure',
                    $node_model,
                    0,
                    'SSH private key column may not be in $hidden array.',
                    'Add ssh_private_key_encrypted to the $hidden array to prevent accidental serialization.'
                );
            }
        }
    }

    /**
     * Scan for mass assignment vulnerabilities.
     *
     * @codeCoverageIgnore Static regex scanner — tested via integration tests on command output.
     */
    protected function scan_mass_assignment(): void
    {
        $this->info('Scanning for mass assignment...');

        $models_path = app_path('Models');
        if (! is_dir($models_path)) {
            return;
        }

        foreach (File::allFiles($models_path) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = $file->getContents();
            $path = $file->getPathname();

            // Check for empty guarded array (allows all mass assignment)
            if (preg_match('/\$guarded\s*=\s*\[\s*\]/', $content)) {
                $this->findings[] = AuditReport::finding(
                    'high',
                    'Mass Assignment',
                    $path,
                    0,
                    'Model has empty $guarded array — all attributes are mass assignable.',
                    'Define a $fillable array with only the fields that should be mass assignable.'
                );
            }

            // Check for missing $fillable and $guarded
            if (! str_contains($content, '$fillable') && ! str_contains($content, '$guarded')) {
                // Only flag if it extends Model
                if (str_contains($content, 'extends Model')) {
                    $this->findings[] = AuditReport::finding(
                        'medium',
                        'Mass Assignment',
                        $path,
                        0,
                        'Model has neither $fillable nor $guarded defined.',
                        'Define a $fillable array to explicitly allow mass assignable fields.'
                    );
                }
            }

            // Check for common typo
            if (preg_match('/\$guraded/', $content)) {
                $this->findings[] = AuditReport::finding(
                    'critical',
                    'Mass Assignment',
                    $path,
                    0,
                    'Typo: $guraded instead of $guarded — mass assignment protection is bypassed.',
                    'Fix the typo: rename $guraded to $guarded.'
                );
            }
        }
    }

    /**
     * Scan for missing input validation in controllers and Livewire components.
     *
     * @codeCoverageIgnore Static regex scanner — tested via integration tests on command output.
     */
    protected function scan_input_validation(): void
    {
        $this->info('Scanning for input validation...');

        $scan_paths = [
            app_path('Livewire'),
            app_path('Http/Controllers'),
        ];

        foreach ($scan_paths as $scan_path) {
            if (! is_dir($scan_path)) {
                continue;
            }

            foreach (File::allFiles($scan_path) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $content = $file->getContents();
                $lines = explode("\n", $content);
                $path = $file->getPathname();

                foreach ($lines as $line_num => $line) {
                    // Detect $request->input() without validation
                    if (preg_match('/\$request->input\s*\(/', $line) && ! str_contains($content, '$this->validate') && ! str_contains($content, 'Validator::make')) {
                        $this->findings[] = AuditReport::finding(
                            'medium',
                            'Input Validation',
                            $path,
                            $line_num + 1,
                            'Request input used without visible validation.',
                            'Add validation rules before using request input.'
                        );
                        break; // One finding per file is enough
                    }
                }

                // Check Livewire components for public properties without validation rules
                if (str_contains($content, 'extends Component')) {
                    // Check if component has any save/create/update methods
                    if (preg_match('/function\s+(save|create|update|store)\s*\(/', $content)) {
                        if (! str_contains($content, '$this->validate') && ! str_contains($content, 'rules()') && ! str_contains($content, '#[Validate')) {
                            $this->findings[] = AuditReport::finding(
                                'medium',
                                'Input Validation',
                                $path,
                                0,
                                'Livewire component has save/create/update method but no visible validation.',
                                'Add $this->validate() or #[Validate] attributes to public properties.'
                            );
                        }
                    }
                }
            }
        }
    }

    /**
     * Scan for XSS vulnerabilities in Blade templates.
     *
     * @codeCoverageIgnore Static regex scanner — tested via integration tests on command output.
     */
    protected function scan_blade_xss(): void
    {
        $this->info('Scanning for XSS in Blade templates...');

        $views_path = resource_path('views');
        if (! is_dir($views_path)) {
            return;
        }

        // User-input-like variable names that are dangerous to output unescaped
        $dangerous_vars = ['name', 'title', 'description', 'comment', 'note', 'message', 'content', 'body', 'input', 'value', 'host', 'ip', 'address', 'username'];

        foreach (File::allFiles($views_path) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $content = $file->getContents();
            $lines = explode("\n", $content);
            $path = $file->getPathname();

            foreach ($lines as $line_num => $line) {
                // Find unescaped Blade output {!! !!}
                if (preg_match('/\{!!\s*(.+?)\s*!!\}/', $line, $matches)) {
                    $expression = $matches[1];

                    // Allow safe uses: json_encode, __(), config(), asset(), etc.
                    if (preg_match('/(?:json_encode|__\(|trans\(|config\(|asset\(|url\(|route\()/', $expression)) {
                        continue;
                    }

                    // Check if expression contains dangerous variable names
                    $is_dangerous = false;
                    foreach ($dangerous_vars as $var) {
                        if (stripos($expression, $var) !== false) {
                            $is_dangerous = true;
                            break;
                        }
                    }

                    $severity = $is_dangerous ? 'high' : 'medium';

                    $this->findings[] = AuditReport::finding(
                        $severity,
                        'XSS',
                        $path,
                        $line_num + 1,
                        'Unescaped Blade output: {!! '.$expression.' !!}',
                        'Use {{ }} for escaped output, or verify this value cannot contain user input.'
                    );
                }
            }
        }
    }

    /**
     * Scan rate limiting configuration.
     *
     * @codeCoverageIgnore Static regex scanner — tested via integration tests on command output.
     */
    protected function scan_rate_limiting(): void
    {
        $this->info('Scanning rate limiting...');

        // Check bootstrap/app.php for throttle middleware
        $app_bootstrap = base_path('bootstrap/app.php');
        if (file_exists($app_bootstrap)) {
            $content = file_get_contents($app_bootstrap);
            if (! str_contains($content, 'throttle')) {
                $this->findings[] = AuditReport::finding(
                    'medium',
                    'Rate Limiting',
                    $app_bootstrap,
                    0,
                    'No throttle middleware detected in bootstrap/app.php.',
                    'Add throttle middleware to protect against brute force attacks.'
                );
            }
        }

        // Check login routes for rate limiting
        $routes_path = base_path('routes/web.php');
        if (file_exists($routes_path)) {
            $content = file_get_contents($routes_path);
            if (str_contains($content, 'login') && ! str_contains($content, 'throttle')) {
                $this->findings[] = AuditReport::finding(
                    'high',
                    'Rate Limiting',
                    $routes_path,
                    0,
                    'Login routes may not have rate limiting applied.',
                    'Apply throttle middleware to login routes.'
                );
            }
        }
    }

    /**
     * Scan session configuration for security.
     *
     * @codeCoverageIgnore Static regex scanner — tested via integration tests on command output.
     */
    protected function scan_session_config(): void
    {
        $this->info('Scanning session configuration...');

        $session_config = config_path('session.php');
        if (! file_exists($session_config)) {
            return;
        }

        $content = file_get_contents($session_config);

        // Check SameSite
        if (preg_match("/['\"]same_site['\"]\s*=>\s*null/", $content)) {
            $this->findings[] = AuditReport::finding(
                'medium',
                'Session Config',
                $session_config,
                0,
                'Session SameSite attribute is null — vulnerable to CSRF via third-party context.',
                "Set 'same_site' to 'lax' or 'strict'."
            );
        }

        // Check secure cookies
        if (preg_match("/['\"]secure['\"]\s*=>\s*false/", $content)) {
            $this->findings[] = AuditReport::finding(
                'medium',
                'Session Config',
                $session_config,
                0,
                'Session secure flag is false — cookies sent over HTTP.',
                "Set 'secure' to true in production."
            );
        }
    }

    /**
     * Scan for credential exposure in code.
     *
     * @codeCoverageIgnore Static regex scanner — tested via integration tests on command output.
     */
    protected function scan_credential_exposure(): void
    {
        $this->info('Scanning for credential exposure...');

        $scan_paths = [
            app_path(),
            config_path(),
        ];

        $credential_patterns = [
            '/(?:password|secret|token|api_key)\s*=\s*["\'][^"\']+["\']/' => 'Hardcoded credential detected',
        ];

        // Skip config files that reference env() — that's the correct pattern
        foreach ($scan_paths as $scan_path) {
            if (! is_dir($scan_path)) {
                continue;
            }

            foreach (File::allFiles($scan_path) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $content = $file->getContents();
                $lines = explode("\n", $content);
                $path = $file->getPathname();

                foreach ($lines as $line_num => $line) {
                    // Skip lines using env() or config() — that's correct
                    if (str_contains($line, 'env(') || str_contains($line, 'config(')) {
                        continue;
                    }

                    // Skip comments
                    $trimmed = ltrim($line);
                    if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '#')) {
                        continue;
                    }

                    // Skip test/example values
                    if (preg_match('/(?:example|test|placeholder|changeme|xxx)/i', $line)) {
                        continue;
                    }

                    foreach ($credential_patterns as $pattern => $description) {
                        if (preg_match($pattern, $line)) {
                            $this->findings[] = AuditReport::finding(
                                'high',
                                'Credential Exposure',
                                $path,
                                $line_num + 1,
                                $description.': '.trim(mb_substr($line, 0, 100)),
                                'Move credentials to .env and reference via env() helper.'
                            );
                        }
                    }
                }
            }
        }
    }

    /**
     * Check the .env file for insecure defaults.
     *
     * @codeCoverageIgnore Static regex scanner — tested via integration tests on command output.
     */
    protected function scan_env_file(): void
    {
        $this->info('Scanning .env file...');

        $env_path = base_path('.env');
        if (! file_exists($env_path)) {
            $this->findings[] = AuditReport::finding(
                'critical',
                'Configuration',
                $env_path,
                0,
                'No .env file found.',
                'Create a .env file from .env.example and configure it.'
            );

            return;
        }

        $content = file_get_contents($env_path);

        // Check APP_KEY is set
        if (preg_match('/^APP_KEY=\s*$/m', $content)) {
            $this->findings[] = AuditReport::finding(
                'critical',
                'Configuration',
                $env_path,
                0,
                'APP_KEY is empty — encryption (including SSH keys) will not work.',
                'Run: php artisan key:generate'
            );
        }

        // Check APP_DEBUG
        if (preg_match('/^APP_DEBUG=true/m', $content)) {
            $this->findings[] = AuditReport::finding(
                'info',
                'Configuration',
                $env_path,
                0,
                'APP_DEBUG is true — acceptable for development, must be false in production.',
                'Set APP_DEBUG=false in production.'
            );
        }

        // Check APP_ENV
        if (preg_match('/^APP_ENV=production/m', $content)) {
            // If production, check debug is false
            if (preg_match('/^APP_DEBUG=true/m', $content)) {
                $this->findings[] = AuditReport::finding(
                    'critical',
                    'Configuration',
                    $env_path,
                    0,
                    'APP_DEBUG is true while APP_ENV is production — stack traces and sensitive data will be exposed.',
                    'Set APP_DEBUG=false immediately.'
                );
            }
        }

        // Check .env permissions
        $perms = substr(sprintf('%o', fileperms($env_path)), -3);
        if ((int) $perms > 600) {
            $this->findings[] = AuditReport::finding(
                'medium',
                'Configuration',
                $env_path,
                0,
                '.env file permissions are '.$perms.' — should be 600 or more restrictive.',
                'Run: chmod 600 .env'
            );
        }
    }

    /**
     * Check for debug mode indicators in production code.
     *
     * @codeCoverageIgnore Static regex scanner — tested via integration tests on command output.
     */
    protected function scan_debug_mode(): void
    {
        $this->info('Scanning for debug artifacts...');

        $scan_paths = [
            app_path('Livewire'),
            app_path('Services'),
            app_path('Jobs'),
            app_path('Http'),
        ];

        $debug_patterns = [
            '/\bdd\s*\(/' => 'dd() call left in code',
            '/\bdump\s*\(/' => 'dump() call left in code',
            '/\bvar_dump\s*\(/' => 'var_dump() call left in code',
            '/\bprint_r\s*\(/' => 'print_r() call left in code',
            '/\bray\s*\(/' => 'ray() debug call left in code',
        ];

        foreach ($scan_paths as $scan_path) {
            if (! is_dir($scan_path)) {
                continue;
            }

            foreach (File::allFiles($scan_path) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $content = $file->getContents();
                $lines = explode("\n", $content);
                $path = $file->getPathname();

                foreach ($lines as $line_num => $line) {
                    $trimmed = ltrim($line);
                    if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
                        continue;
                    }

                    foreach ($debug_patterns as $pattern => $description) {
                        if (preg_match($pattern, $line)) {
                            $this->findings[] = AuditReport::finding(
                                'low',
                                'Debug Artifact',
                                $path,
                                $line_num + 1,
                                $description,
                                'Remove debug statements before deploying to production.'
                            );
                        }
                    }
                }
            }
        }
    }

    /**
     * Verify encryption configuration is properly set up.
     *
     * @codeCoverageIgnore Static regex scanner — tested via integration tests on command output.
     */
    protected function scan_encryption_config(): void
    {
        $this->info('Scanning encryption configuration...');

        // Check that the database has the encrypted column
        $node_migration = null;
        $migrations_path = database_path('migrations');
        if (is_dir($migrations_path)) {
            foreach (File::allFiles($migrations_path) as $file) {
                if (str_contains($file->getFilename(), 'create_nodes_table')) {
                    $node_migration = $file;
                    break;
                }
            }
        }

        if ($node_migration) {
            $content = $node_migration->getContents();
            if (str_contains($content, 'ssh_private_key') && ! str_contains($content, 'text')) {
                $this->findings[] = AuditReport::finding(
                    'high',
                    'Encryption',
                    $node_migration->getPathname(),
                    0,
                    'SSH key column may not be a text type — encrypted values require text/longText.',
                    'Use $table->text() for encrypted columns.'
                );
            }
        }

        // Check SQLite database permissions
        $db_path = database_path('database.sqlite');
        if (file_exists($db_path)) {
            $perms = substr(sprintf('%o', fileperms($db_path)), -3);
            if ((int) $perms > 640) {
                $this->findings[] = AuditReport::finding(
                    'medium',
                    'Encryption',
                    $db_path,
                    0,
                    'SQLite database permissions are '.$perms.' — contains encrypted SSH keys.',
                    'Run: chmod 640 database/database.sqlite'
                );
            }
        }
    }

    /**
     * Remove known false positives from findings.
     */
    protected function suppress_false_positives(array $findings): array
    {
        return array_values(array_filter($findings, function ($finding) {
            foreach ($this->false_positives as [$category, $file_suffix, $desc_substring, $reason]) {
                if (
                    $finding['category'] === $category &&
                    str_ends_with($finding['file'], $file_suffix) &&
                    str_contains($finding['description'], $desc_substring)
                ) {
                    return false;
                }
            }

            return true;
        }));
    }
}
