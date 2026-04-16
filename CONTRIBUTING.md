# Contributing to PHPMyCluster

Thanks for your interest in contributing! PHPMyCluster is an open-source control plane for managing MySQL InnoDB and Redis Sentinel clusters, and we welcome bug reports, feature requests, documentation improvements, and pull requests.

Please take a moment to review this document before contributing — it will save time for both you and the maintainers.

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [Reporting Bugs](#reporting-bugs)
- [Suggesting Features](#suggesting-features)
- [Security Issues](#security-issues)
- [Development Setup](#development-setup)
- [Making Changes](#making-changes)
- [Coding Standards](#coding-standards)
- [Testing](#testing)
- [Commit Messages](#commit-messages)
- [Pull Request Process](#pull-request-process)
- [Project Conventions](#project-conventions)

## Code of Conduct

Be respectful. Assume good intent. Disagree without being disagreeable. Harassment, discrimination, and personal attacks will not be tolerated. If you experience or witness unacceptable behaviour, please open an issue or contact the maintainer directly.

## Reporting Bugs

Before opening a bug report, please:

1. **Check existing issues** — search [the issue tracker](https://github.com/ryanlovett-au/phpmycluster/issues) to see if the bug has already been reported.
2. **Reproduce on the latest `main`** — make sure the bug still exists with the most recent code.
3. **Run the security audit** — `php artisan security:audit` is a good sanity check if the bug involves user input or shell commands.

A good bug report includes:

- **A clear title** describing the problem
- **Steps to reproduce** — exact commands, UI clicks, or API calls
- **Expected behaviour** vs **actual behaviour**
- **Environment details** — PHP version, database (SQLite/MySQL), OS, browser
- **Relevant logs** — from `storage/logs/laravel.log`, queue worker output, or browser console
- **Screenshots** if the bug is UI-related

Please redact any sensitive information (SSH keys, passwords, real server IPs) before posting.

## Suggesting Features

Feature requests are welcome! Before opening one, please:

1. **Check existing issues and discussions** — someone may have already suggested it.
2. **Explain the use case** — what problem does this solve? Who benefits?
3. **Consider the scope** — PHPMyCluster focuses on MySQL InnoDB Cluster and Redis Sentinel management. Features outside that scope (e.g. PostgreSQL, MongoDB, application deployment) are unlikely to be accepted.

If you're planning a large feature, please open an issue to discuss it *before* writing code. This avoids wasted effort if the maintainer has a different vision for the feature.

## Security Issues

If you discover a security vulnerability, please report it by [opening an issue](https://github.com/ryanlovett-au/phpmycluster/issues) in the GitHub issue tracker. Include as much detail as possible — steps to reproduce, affected versions, and potential impact.

## Development Setup

Follow the [installation instructions in the README](README.md#requirements) to get the app running locally. You'll also want a few Linux VMs with SSH access (Ubuntu 22.04+ recommended) for testing cluster operations.

### Running the queue worker

Many operations (cluster provisioning, refresh, firewall changes) run as background jobs. If you're not using `composer run dev`, start the worker manually:

```bash
php artisan queue:work --queue=default
```

## Making Changes

1. **Fork** the repository on GitHub.
2. **Create a branch** from `main` with a descriptive name:
   - `feature/redis-cluster-rename` for new features
   - `fix/router-firewall-delete` for bug fixes
   - `docs/update-readme` for documentation
3. **Make your changes** following the coding standards below.
4. **Write or update tests** for any behaviour change.
5. **Run the full test suite, linter, and build** before pushing.
6. **Open a pull request** against `main`.

## Coding Standards

### PHP

- **PSR-12 + Laravel Pint** — run `./vendor/bin/pint` before committing. Pint enforces the project's code style automatically.
- **Type declarations** — use typed parameters, return types, and property types wherever possible.
- **Explicit `$fillable`** — never use `$guarded = []` on Eloquent models. Mass assignment protection is enforced.
- **Input validation** — any Livewire component accepting user input must validate with `$this->validate([...])`. The security audit will flag missing validation.
- **Shell commands** — any value passed to SSH or `mysqlsh` must be escaped (`escapeshellarg()`) or validated against a strict allowlist. Never interpolate raw user input into shell commands.

### JavaScript / Blade

- **Tailwind CSS v4** — the project uses the v4 config-less setup (`@import 'tailwindcss'`, `@source`, `@theme`). Do **not** add a `tailwind.config.js`.
- **Flux UI** — use Flux components where possible. Valid button variants are `primary`, `filled`, `outline`, `danger`, `ghost`, `subtle` (**not** `warning`). Valid sizes are `base`, `sm`, `xs` (**not** `lg`).
- **No inline styles as workarounds** — if a Tailwind class is being overridden by Flux, fix the root cause rather than patching with `style=""`.
- **Rebuild assets** after modifying blade templates: `npm run build`.

### Naming

- Use **"Primary Node"** terminology for MySQL cluster primaries — never "Seed Node" or "Master".
- MySQL routes are prefixed `mysql.*`, Redis routes `redis.*`. Stay consistent with this scheme.
- Livewire property names for "add new node" forms use the `newNode*` prefix on both cluster types.
- Keep MySQL and Redis management patterns symmetrical — if you add a feature to one, add the equivalent to the other.

## Testing

PHPMyCluster has a comprehensive Pest test suite with high coverage. **All changes must include tests**.

```bash
# Run the full suite
./vendor/bin/pest

# Run a specific test file
./vendor/bin/pest tests/Feature/ClusterManagerFullTest.php

# Filter by test name
./vendor/bin/pest --filter="renames a node"

# With coverage
./vendor/bin/pest --coverage
```

### Test guidelines

- **No risky tests** — every test must include at least one assertion. If you're testing a "nothing happens" case, assert the expected no-op state explicitly.
- **Unit tests** for services and pure functions (`tests/Unit`).
- **Feature tests** for Livewire components, jobs, and HTTP routes (`tests/Feature`).
- **Mock external services** — never make real SSH connections, MySQL Shell calls, or HTTP requests in tests. Use `Bus::fake()`, `Queue::fake()`, and mocked service classes.

### Security audit

Before opening a PR that touches SSH commands, user input, or database queries, run:

```bash
php artisan security:audit
```

This static scanner will flag common vulnerabilities specific to the project.

## Commit Messages

Follow these conventions:

- **Imperative mood, present tense** — "Add Redis failover action" not "Added Redis failover action".
- **Short first line** — under ~72 characters, summarising the change.
- **Blank line** between the subject and body.
- **Body** (optional) — explain *why* the change was made, not *what* (the diff shows the what).
- **One logical change per commit** — don't mix refactoring with bug fixes or feature work.

Example:

```
Unify MySQL and Redis cluster management patterns

Refresh polling, progress tracking, log streaming, and property
naming now match across both cluster types, making behaviour
consistent and predictable for operators managing both.
```

Do **not** include `Co-Authored-By` trailers for AI assistants unless the maintainer explicitly asks.

## Pull Request Process

1. **Ensure your branch is up to date** with `main`:
   ```bash
   git fetch origin
   git rebase origin/main
   ```
2. **Run the checklist**:
   - [ ] `./vendor/bin/pint` passes
   - [ ] `npm run build` succeeds
   - [ ] `./vendor/bin/pest` — all tests green, zero risky
   - [ ] `php artisan security:audit` — no new findings
   - [ ] Documentation updated if behaviour changed
   - [ ] MySQL/Redis symmetry preserved where applicable
3. **Push your branch** and open a pull request against `main`.
4. **Fill out the PR description** with:
   - What the change does
   - Why it's needed
   - How it was tested (include the test plan)
   - Screenshots for UI changes
5. **Respond to review feedback** promptly. Maintainers may request changes, additional tests, or a different approach.
6. **Keep the PR focused** — if review uncovers unrelated issues, open a separate PR for them.

PRs that fail CI, lack tests, or break the MySQL/Redis consistency contract will not be merged.

## Project Conventions

A few things specific to this project that are easy to miss:

- **Destructive actions** — never delete data, remove packages, or overwrite configuration without explicit user confirmation. UI buttons for destructive operations must use `wire:confirm` or an equivalent.
- **Cache-based progress tracking** — long-running jobs report progress via the `TracksProgress` trait, which writes to Laravel's cache. Livewire components poll the cache key to display step-by-step output.
- **Bus::batch for refresh polling** — all cluster manager pages use `Bus::batch()` + `Bus::findBatch()` polling for refresh operations, with a 2-second poll interval.
- **MySQL packages** — server provisioning must install MySQL from the official `repo.mysql.com` APT repository, not Ubuntu's default packages. This is non-negotiable (Ubuntu's package is 8.0, which doesn't support AdminAPI cluster rescue operations the same way).
- **`SshConnectable` interface** — new node-like models that need SSH access should implement this interface so `SshService` can connect polymorphically.

## Questions?

If you're unsure about anything, open a discussion or draft PR and ask. It's much better to ask early than to spend days building something that doesn't fit the project.

Thanks for contributing!
