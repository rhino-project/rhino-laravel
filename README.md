# Rhino — Laravel

> Automatic REST API generation for Laravel Eloquent models with built-in security, validation, and advanced querying.

[![PHP Version](https://img.shields.io/badge/php-8.0%2B-blue)](https://www.php.net/)
[![Laravel Version](https://img.shields.io/badge/laravel-10%2B-red)](https://laravel.com/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

Register a model, get a full REST API instantly.

## Features

| # | Feature | Description |
|---|---------|-------------|
| 1 | **Automatic CRUD Endpoints** | Generates `index`, `show`, `store`, `update`, `destroy` for every registered model. |
| 2 | **Authentication** | Login, logout, password recovery/reset, invitation-based registration via Sanctum. |
| 3 | **Authorization & Policies** | Convention-based permission checks (`{slug}.{action}`), wildcard support. |
| 4 | **Role-Based Access Control** | Per-org roles via `user_roles` pivot table. |
| 5 | **Attribute-Level Permissions** | Control which fields each role can read and write. |
| 6 | **Validation** | Dual-layer: format rules + field presence. Supports role-keyed rules. |
| 7 | **Cross-Tenant FK Validation** | `exists:` rules auto-scoped to current org, even through indirect FK relationships. |
| 8 | **Filtering** | `?filter[field]=value` with AND/OR logic. |
| 9 | **Sorting** | `?sort=-created_at,title` — ascending and descending. |
| 10 | **Full-Text Search** | `?search=term` across configured fields, supports relationship dot notation. |
| 10a | **Named Scopes** | `?scope=name` applies a model-whitelisted Eloquent named scope (with an optional `$defaultScope`); the current user is injected; non-whitelisted names return 403. Applies to index/trashed. |
| 11 | **Pagination** | Header-based metadata (`X-Current-Page`, `X-Last-Page`, `X-Per-Page`, `X-Total`). |
| 12 | **Field Selection** | `?fields[posts]=id,title,status` to reduce payload. |
| 13 | **Eager Loading** | `?include=user,comments` with nested, Count/Exists suffixes, and auth per include. |
| 14 | **Multi-Tenancy** | Organization-based data isolation, auto-set `organization_id`, global scope. |
| 15 | **Nested Ownership** | Auto-detects org by walking `BelongsTo` chains. |
| 16 | **Route Groups** | Multiple URL prefixes with different middleware/auth (`tenant`, `public`, custom). |
| 17 | **Soft Deletes** | Trash, restore, force-delete endpoints with individual permissions. |
| 18 | **Audit Trail** | Logs all CRUD events with old/new values, user, IP, and org context. |
| 19 | **Nested Operations** | `POST /nested` for atomic multi-model transactions with `$N.field` references. |
| 20 | **Invitations** | Token-based invite system with create, resend, cancel, accept, and role assignment. |
| 21 | **Hidden Columns** | Base + model-level + policy-level dynamic column hiding per role. |
| 22 | **Auto-Scope Discovery** | Auto-registers scopes by naming convention. |
| 23 | **UUID Primary Keys** | `HasUuid` trait for auto-generated UUIDs. |
| 24 | **Middleware Support** | Global per model + per action middleware. |
| 25 | **Action Exclusion** | `$exceptActions` to disable specific CRUD routes. |
| 26 | **Generator CLI** | `rhino:install`, `rhino:generate`, `rhino:blueprint`, `rhino:export-postman`. |
| 27 | **Postman Export** | Auto-generated Postman Collection v2.1 with all endpoints. |
| 28 | **Blueprint System** | YAML-to-code generation for models, migrations, factories, policies, tests, and seeders. |

## Quick Start

```bash
composer require rhino-project/rhino-laravel dev-main
php artisan rhino:install
```

## Documentation

For full documentation, guides, and API reference visit:

**[https://rhino-project.github.io/rhino-docs/docs/getting-started](https://rhino-project.github.io/rhino-docs/docs/getting-started)**

## License

MIT — see [LICENSE](LICENSE) for details.
