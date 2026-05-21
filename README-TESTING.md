# Testing the Laravel Global Controller Package

This package includes a comprehensive test suite that can be run independently, even when the package is embedded in a parent project.

## Test Structure

The package tests are organized into three suites:

1. **Unit Tests** (`tests/Unit/`): Test individual components in isolation
2. **Feature Tests** (`tests/Feature/`): Test functionality without multi-tenant support
3. **Multi-Tenant Tests** (`tests/MultiTenant/`): Test multi-tenant specific features

## Running Tests

### From the Package Directory

First, install dependencies:

```bash
cd packages/Startsoft/laravel-global-controller
composer install
```

Then run tests:

```bash
./vendor/bin/phpunit
```

### From the Root Project

You can also run package tests from the root:

```bash
cd packages/Startsoft/laravel-global-controller
php ../../vendor/bin/phpunit
```

### Running Specific Test Suites

```bash
# Run only unit tests (no multi-tenant setup required)
./vendor/bin/phpunit --testsuite Unit

# Run only feature tests (no multi-tenant setup required)
./vendor/bin/phpunit --testsuite Feature

# Run only multi-tenant tests
./vendor/bin/phpunit --testsuite MultiTenant
```

## Test Environment

- **Database**: Uses SQLite in-memory database (`:memory:`) for fast, isolated tests
- **No Dependencies**: Tests don't require the parent project to have multi-tenant setup
- **Orchestra Testbench**: Uses Laravel's official package testing framework
- **Isolated**: Each test runs in a clean environment

## Writing Tests

### Basic Test Structure

```php
<?php

namespace Startsoft\LaravelGlobalController\Tests\Feature;

use Startsoft\LaravelGlobalController\Tests\TestCase;

class MyFeatureTest extends TestCase
{
    public function test_something()
    {
        // Your test code
    }
}
```

### Testing Without Multi-Tenant

By default, tests run without multi-tenant enabled. To test multi-tenant features, use the `MultiTenant` test suite or enable it in your test:

```php
protected function setUp(): void
{
    parent::setUp();
    config(['global-controller.multi_tenant.enabled' => true]);
}
```

### Testing With Database

Use `RefreshDatabase` trait for database tests:

```php
use Illuminate\Foundation\Testing\RefreshDatabase;

class DatabaseTest extends TestCase
{
    use RefreshDatabase;
    
    // Your tests
}
```

### Loading Test Migrations

For multi-tenant tests, load migrations from the test directory:

```php
protected function setUp(): void
{
    parent::setUp();
    $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
}
```

## Test Coverage

The test suite covers:

- ✅ GlobalController basic functionality
- ✅ Middleware (ResolveOrganizationFromRoute, ResolveOrganizationFromSubdomain)
- ✅ Multi-tenant scoping
- ✅ Organization resolution
- ✅ User-organization access control
- ✅ Configuration handling
- ✅ Route groups (tenant, public, custom groups)
- ✅ Permission resolution (user-level via `users.permissions`, org-scoped via `user_roles.permissions`)
- ✅ Wildcard permissions (`*`, `resource.*`)
- ✅ Soft delete permissions (trashed, restore, forceDelete)
- ✅ Attribute permissions (field-level read/write control)

## Continuous Integration

The package tests can be run in CI/CD pipelines independently of the parent project:

```yaml
# Example GitHub Actions
- name: Run Package Tests
  run: |
    cd packages/Startsoft/laravel-global-controller
    composer install
    ./vendor/bin/phpunit
```

## Benefits

1. **Isolated Testing**: Tests run independently of the parent project
2. **No Setup Required**: Multi-tenant features can be tested without installing them in the parent project
3. **Fast Execution**: Uses in-memory SQLite for quick test runs
4. **CI/CD Ready**: Can be integrated into continuous integration pipelines
5. **Comprehensive Coverage**: Separate test suites for different scenarios
