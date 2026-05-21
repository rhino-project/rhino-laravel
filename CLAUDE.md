# Rhino Laravel Server — Development Guide

This is **Rhino**, a Laravel package that auto-generates fully-featured REST APIs from model definitions. It is a PHP library (not an application) — you are editing the framework itself, not a project that uses it.

## Project Structure

```
src/
├── Blueprint/                  # YAML-to-code generation system
│   ├── BlueprintParser.php
│   ├── BlueprintValidator.php
│   ├── ManifestManager.php
│   └── Generators/             # PolicyGenerator, TestGenerator, SeederGenerator
├── Commands/                   # Artisan commands (install, generate, blueprint, postman, invitation)
├── Contracts/                  # Interfaces (HasRoleBasedValidation, HasHiddenColumns, HasPermittedAttributes)
├── Controllers/
│   ├── GlobalController.php    # Main CRUD controller — handles ALL endpoints automatically
│   ├── AuthController.php      # Login, logout, password recovery/reset, registration
│   └── InvitationController.php # Invitation CRUD + accept
├── Http/Middleware/
│   └── ResolveOrganizationFromRoute.php  # Multi-tenant org resolution
├── Models/
│   ├── RhinoModel.php         # Base model (includes SoftDeletes, HasValidation, HidableColumns, HasAutoScope)
│   ├── AuditLog.php            # Polymorphic audit log
│   └── OrganizationInvitation.php
├── Policies/
│   └── ResourcePolicy.php      # Base authorization policy
├── Traits/
│   ├── HasValidation.php       # Request validation + cross-tenant FK scoping
│   ├── BelongsToOrganization.php # Multi-tenant data isolation
│   ├── HasAuditTrail.php       # Automatic change logging
│   ├── HidableColumns.php      # Dynamic column visibility
│   ├── HasAutoScope.php        # Auto-discover scopes by naming convention
│   ├── HasPermissions.php      # Permission checking (User model)
│   ├── HasUuid.php             # Auto-generated UUID primary keys
│   └── ViewModelHelpers.php    # Currency formatting helpers
├── Scopes/                     # Global query scopes
└── Notifications/              # Email notifications (invitations, password reset)
config/rhino.php               # Default configuration
tests/
├── Feature/                    # HTTP endpoint tests
├── Unit/                       # Trait, model, policy unit tests
├── Models/                     # Test model definitions
├── MultiTenant/                # Tenant isolation tests
└── database/                   # Test migrations and factories
```

## Features

This library provides the following features. When modifying or extending any of them, you must understand how they interconnect:

| # | Feature | Key Files |
|---|---------|-----------|
| 1 | **Automatic CRUD Endpoints** (index, show, store, update, destroy) | `GlobalController.php` |
| 2 | **Authentication** (login, logout, password recovery/reset, invitation registration) | `AuthController.php` |
| 3 | **Authorization & Policies** (convention-based `{slug}.{action}` permissions, wildcards) | `ResourcePolicy.php`, `HasPermissions.php` |
| 4 | **Role-Based Access Control** (per-org roles via user_roles pivot) | `HasPermissions.php` |
| 5 | **Attribute-Level Permissions** (read/write field control per role) | `ResourcePolicy.php`, `HidableColumns.php` |
| 6 | **Validation** (format rules via `$validationRules`, field presence via store/update rules, role-keyed) | `HasValidation.php` |
| 7 | **Cross-Tenant FK Validation** (auto-scopes `exists:` rules to org, even through indirect FK relationships) | `HasValidation.php` |
| 8 | **Filtering** (`?filter[field]=value`, AND/OR logic) | `GlobalController.php` (Spatie Query Builder) |
| 9 | **Sorting** (`?sort=-created_at,title`) | `GlobalController.php` |
| 10 | **Full-Text Search** (`?search=term`, dot-notation for relationships) | `GlobalController.php` |
| 11 | **Pagination** (header-based: X-Current-Page, X-Last-Page, X-Per-Page, X-Total) | `GlobalController.php` |
| 12 | **Field Selection** (`?fields[posts]=id,title`) | `GlobalController.php` |
| 13 | **Eager Loading** (`?include=user,comments`, nested, Count/Exists suffixes, auth per include) | `GlobalController.php` |
| 14 | **Multi-Tenancy** (org-based data isolation, auto-set org_id, global scope) | `BelongsToOrganization.php`, `ResolveOrganizationFromRoute.php` |
| 15 | **Nested Ownership Auto-Detection** (walks BelongsTo chains to find org) | `GlobalController.php`, `HasValidation.php` |
| 16 | **Route Groups** (tenant, public, custom groups with different middleware/auth) | `config/rhino.php`, route registration |
| 17 | **Soft Deletes** (trash, restore, force-delete endpoints + permissions) | `GlobalController.php`, `RhinoModel.php` |
| 18 | **Audit Trail** (logs all CRUD events with old/new values, user, IP, org) | `HasAuditTrail.php`, `AuditLog.php` |
| 19 | **Nested Operations** (POST /nested, atomic transactions, $N.field references) | `GlobalController.php` |
| 20 | **Invitations** (token-based, create/resend/cancel/accept, configurable expiry) | `InvitationController.php`, `OrganizationInvitation.php` |
| 21 | **Hidden Columns** (base + model-level + policy-level dynamic hiding) | `HidableColumns.php` |
| 22 | **Auto-Scope Discovery** (naming convention: `App\Models\Scopes\{Model}Scope`) | `HasAutoScope.php` |
| 23 | **UUID Primary Keys** | `HasUuid.php` |
| 24 | **Middleware Support** (global per model + per action) | `GlobalController.php` |
| 25 | **Action Exclusion** (`$exceptActions` to disable specific routes) | Route registration, `GlobalController.php` |
| 26 | **Generator CLI** (`rhino:install`, `rhino:generate`, `rhino:blueprint`) | `Commands/` |
| 27 | **Postman Export** (auto-generated collection with all endpoints) | `ExportPostmanCommand.php` |
| 28 | **Blueprint System** (YAML-to-code generation for models, policies, tests, seeders) | `Blueprint/` |

## Running Tests

```bash
# Run all tests
./vendor/bin/phpunit

# Run specific test file
./vendor/bin/phpunit tests/Feature/CrudTest.php

# Run specific test method
./vendor/bin/phpunit --filter="it_lists_posts"

# Run only unit tests
./vendor/bin/phpunit tests/Unit/

# Run only feature tests
./vendor/bin/phpunit tests/Feature/
```

**All tests MUST pass before any change is considered complete.**

## Development Rules

### 1. Tests Are Mandatory — No Exceptions

Every change to this library MUST include tests:

- **New feature**: Write feature tests (HTTP endpoint behavior) AND unit tests (individual method/trait logic). Cover ALL scenarios:
  - Happy path (200, 201)
  - Authorization denied (403)
  - Not found (404)
  - Validation errors (422)
  - Role-based access for EVERY permission level
  - Multi-tenant isolation (org A data must not leak to org B)
  - Edge cases (empty data, null values, max limits)

- **Bug fix**: Write a test that reproduces the bug FIRST (it should fail), then fix the code (test should pass). This prevents regressions.

- **Refactor**: All existing tests must continue to pass. Add tests for any edge cases discovered during refactoring.

**Test coverage goal: maximum. Every public method, every endpoint, every permission boundary.**

### 2. All Existing Tests Must Pass

Before finishing any change, run the full test suite:

```bash
./vendor/bin/phpunit
```

If any test fails, fix it. Do NOT skip or disable tests. If a test is genuinely wrong (not your code), fix the test.

### 3. Update Documentation for Every Feature Change

When you add or modify a feature in this library, you MUST also update:

1. **Rhino Docs** — The Docusaurus documentation site at `../rhino-docs/docs/server/`:
   - Find the relevant doc page and update it
   - If adding a new feature, create a new doc page or add to the appropriate existing page

2. **Rhino Skill File** — The AI reference file at `../rhino-docs/static/skills/server/SKILL.md`:
   - Update the Feature Summary table if adding a new feature
   - Update the relevant section with new/changed behavior
   - Add Q&A entries for common questions about the change
   - Update code examples if the API changed

**The docs and skill file are the source of truth for users and AI assistants. If they're outdated, users will get wrong information.**

### 4. Maintain Consistency Across Stacks

Rhino exists in three stacks (Laravel, AdonisJS, Rails). When adding a feature to this Laravel version:

- Check if the same feature should be added to `../rhino-adonis-server/` and `../rhino-server/`
- Keep the API surface (URL patterns, query parameters, response format, behavior) identical across stacks
- Keep the YAML blueprint format identical across stacks

### 5. Code Conventions

- Follow PSR-12 coding standards
- Use type hints on all method parameters and return types
- Use PHPDoc blocks for complex methods
- Keep `GlobalController.php` as the single CRUD handler — do NOT create per-model controllers
- New traits go in `src/Traits/`, new commands in `src/Commands/`
- Configuration options go in `config/rhino.php` with sensible defaults
- All new model properties should have `public static` visibility for consistency

### 6. Multi-Tenancy Safety

When modifying any code that touches data:
- NEVER trust client-supplied `organization_id`
- Always use the org from request attributes (set by middleware), never from user input
- Test cross-tenant isolation: create data in org A, request from org B, verify 404/empty
- FK validation must scope to current org (direct or via chain)

### 7. Backward Compatibility

This is a published package. Breaking changes require:
- Major version bump
- Migration guide in docs
- Deprecation warnings in the previous minor version when possible
