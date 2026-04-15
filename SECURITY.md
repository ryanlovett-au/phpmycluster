# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| latest  | :white_check_mark: |

## Reporting a Vulnerability

If you discover a security vulnerability in PHPMyCluster, please report it by [opening a GitHub issue](https://github.com/your-org/phpmycluster/issues/new).

Please include the following details:

- A description of the vulnerability
- Steps to reproduce the issue
- The potential impact of the vulnerability
- Any suggested fixes (if applicable)

## Security Model

PHPMyCluster acts as a control plane that manages remote servers over SSH — similar to tools like Ansible, Terraform, and Laravel Forge. This means the control node holds the credentials needed to manage your infrastructure.

### SSH Key Storage

SSH private keys are stored in the SQLite database, encrypted at rest using Laravel's `encrypted` cast (AES-256-CBC). Decryption requires the `APP_KEY` from your `.env` file.

Any process with access to both the database and the `APP_KEY` can decrypt the SSH keys. This includes the web server process and queue workers, which need the keys to connect to cluster nodes and execute management commands.

**This is by design** — the control plane must hold credentials to function. The important thing is to protect the control node itself.

## Security Hardening

PHPMyCluster applies the following security measures:

- **Input Validation** — All user-supplied identifiers (usernames, hostnames, database names) passed to MySQL Shell JS commands are validated against a strict allowlist (`[a-zA-Z0-9_.\-%@/]`). Invalid characters throw an `InvalidArgumentException` before any command is executed, preventing shell and SQL injection.
- **Password Sanitisation** — Passwords embedded in MySQL Shell commands are escaped with `addslashes()` to handle quotes and backslashes safely within the JS/SQL nesting.
- **Mass Assignment Protection** — All Eloquent models use explicit `$fillable` arrays rather than `$guarded = []`, preventing unintended attribute assignment.
- **Sensitive Data Hidden** — The `ssh_private_key_encrypted` attribute is listed in the Node model's `$hidden` array, preventing accidental exposure in JSON responses or logs.
- **Rate Limiting** — Web routes are throttled to 120 requests per minute per IP. Authentication routes (login, two-factor) have stricter dedicated rate limiters (5 per minute).
- **Encrypted Storage** — SSH private keys, MySQL root passwords, and cluster admin passwords are stored with Laravel's `encrypted` cast (AES-256-CBC), requiring the `APP_KEY` to decrypt.

## Security Best Practices

When deploying PHPMyCluster, please ensure:

- **Restrict access to the control node** — it should not be publicly accessible. Limit access to trusted networks or use a VPN.
- **Protect your `.env` file** — it contains the `APP_KEY` used to decrypt SSH keys. Never commit it to version control. Set restrictive file permissions (`chmod 600`).
- **Protect the SQLite database** — it contains encrypted SSH keys and cluster configuration. Ensure it is not accessible from the web server's document root and has restrictive file permissions.
- **Use dedicated SSH keys** — generate keys specifically for PHPMyCluster rather than reusing personal keys. This makes it easy to revoke access if the control node is compromised.
- **Limit SSH user privileges** — where possible, restrict the SSH user's sudo access to only the commands PHPMyCluster needs (MySQL, systemctl, ufw, apt).
- **Run queue workers under a dedicated system user** — avoid running them as root.
- **Keep dependencies up to date** — regularly run `composer update` and `npm update` to patch vulnerabilities.
- **Back up your `APP_KEY`** — if you lose it, all encrypted SSH keys in the database become unrecoverable.
- **Run the security audit regularly** — use the built-in static analysis tool to check for vulnerabilities.

## Security Audit

PHPMyCluster includes a built-in static security scanner that checks for common vulnerabilities specific to this application.

```bash
# Run the full audit
php artisan security:audit

# Show remediation suggestions
php artisan security:audit --fix

# Filter by severity
php artisan security:audit --severity=critical

# Output as JSON (for CI pipelines)
php artisan security:audit --output=json
```

The audit scans for:

- **Command Injection** — SSH commands with unsanitised user input
- **SQL Injection** — raw queries with string interpolation
- **SSH Key Exposure** — private keys logged, dumped, or exposed in responses
- **Mass Assignment** — models missing `$fillable` or with empty `$guarded`
- **Input Validation** — controllers and Livewire components missing validation
- **XSS** — unescaped Blade output (`{!! !!}`) with user-controlled data
- **Rate Limiting** — missing throttle middleware on authentication routes
- **Session Configuration** — insecure cookie settings
- **Credential Exposure** — hardcoded secrets in source code
- **Configuration** — `.env` file permissions, debug mode, missing `APP_KEY`
- **Debug Artifacts** — `dd()`, `dump()`, `var_dump()` left in code
- **Encryption** — SSH key column types and database file permissions

The command returns a non-zero exit code if any critical or high severity findings are detected, making it suitable for CI/CD pipelines.
