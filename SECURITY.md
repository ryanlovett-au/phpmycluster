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
