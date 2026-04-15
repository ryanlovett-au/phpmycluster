# Testing

PHPMyCluster uses [Pest PHP](https://pestphp.com/) for testing with [pcov](https://github.com/krakjoe/pcov) for code coverage.

## Requirements

- PHP 8.3+ with pcov extension
- SQLite (tests use `:memory:` database)

## Running Tests

```bash
# Run all tests
./vendor/bin/pest

# Run with coverage report
./vendor/bin/pest --coverage

# Run a specific test file
./vendor/bin/pest tests/Unit/Models/ClusterTest.php

# Run tests matching a filter
./vendor/bin/pest --filter="adds a node"

# Run only unit tests
./vendor/bin/pest tests/Unit/

# Run only feature tests
./vendor/bin/pest tests/Feature/
```

## Test Structure

```
tests/
├── Pest.php                          # Test configuration, helpers, RefreshDatabase
├── Unit/
│   ├── Enums/                        # Enum label/value tests (MySQL + Redis enums)
│   ├── Jobs/                         # Job dispatch, handle, and trait tests
│   ├── Models/                       # Relationships, casts, fillable, hidden
│   ├── Providers/                    # Service provider registration
│   ├── Security/                     # AuditReport tool tests
│   └── Services/                     # Service classes with mocked SSH
│       ├── MysqlShellServiceTest.php # Includes input validation/injection tests
│       └── RedisCliServiceTest.php   # Redis CLI command construction tests
└── Feature/
    ├── Auth/                         # First user auto-approval
    ├── Middleware/                    # EnsureUserIsApproved middleware
    ├── Settings/                     # Profile and security settings
    ├── ClusterManagerFullTest.php    # MySQL Livewire component tests
    ├── ClusterSetupWizardFullTest.php
    ├── RedisClusterManagerTest.php   # Redis Livewire component tests
    ├── RedisSetupWizardTest.php
    ├── SecurityAuditCommandTest.php  # Artisan command tests
    └── ...
```

## Test Helpers

Defined in `tests/Pest.php`:

```php
createAdmin()          // Creates an approved admin user
createApprovedUser()   // Creates an approved non-admin user
createPendingUser()    // Creates a pending (unapproved) user
```

## Test Database

All tests use SQLite `:memory:` via the `RefreshDatabase` trait (configured globally in `Pest.php`). No external database or services are required.

## Mocking Strategy

Since PHPMyCluster manages remote servers via SSH, all service tests use **Mockery** to mock SSH connections:

- **SshService** — SSH2/SFTP mocked via `Mockery::mock(SSH2::class)`
- **MysqlShellService** — SshService injected and mocked
- **RedisCliService** — SshService injected and mocked
- **FirewallService** — SshService injected and mocked
- **NodeProvisionService** — SshService and Http facade mocked (includes auto-tuning detection)
- **Jobs** — All services mocked via constructor injection (MySQL and Redis provisioning jobs)
- **Livewire components** — Services mocked via `$this->mock(Service::class)`
- **Input Validation** — `validateIdentifier()` tested with 9 injection vectors (semicolons, backticks, quotes, `$(...)`, pipes, etc.)
- **Model Security** — `$fillable` and `$hidden` attributes verified on all models

## Model Factories

### MySQL

```php
MysqlCluster::factory()->online()->create()      // Online cluster
MysqlCluster::factory()->degraded()->create()    // Degraded cluster
MysqlCluster::factory()->offline()->create()     // Offline cluster

MysqlNode::factory()->primary()->create()        // Primary DB node
MysqlNode::factory()->secondary()->create()      // Secondary DB node
MysqlNode::factory()->access()->create()         // Router/access node
MysqlNode::factory()->access()->offline()->create() // Offline router
```

### Redis

```php
RedisCluster::factory()->online()->create()      // Online cluster
RedisCluster::factory()->offline()->create()     // Offline cluster

RedisNode::factory()->master()->create()         // Master node
RedisNode::factory()->replica()->create()        // Replica node
```

## Code Coverage

Generate a coverage report:

```bash
# Terminal coverage summary
./vendor/bin/pest --coverage

# HTML coverage report
./vendor/bin/pest --coverage --coverage-html=coverage-report

# Minimum coverage threshold (fails if below)
./vendor/bin/pest --coverage --min=95
```

## Code Style

Always run Pint after modifying PHP files:

```bash
./vendor/bin/pint
```

## Continuous Integration

The test suite is designed to run without any external dependencies (no MySQL, no SSH servers, no network). All external interactions are mocked.

```bash
# CI pipeline example
composer install --no-interaction
php artisan key:generate --env=testing
./vendor/bin/pest --coverage --min=95
```
