# Tests

## Running Tests

```bash
# Run all tests
./vendor/bin/phpunit

# Run a specific suite
./vendor/bin/phpunit --testsuite Unit
./vendor/bin/phpunit --testsuite Feature
./vendor/bin/phpunit --testsuite MultiTenant

# Run a specific test file
./vendor/bin/phpunit tests/Unit/HidableColumnsTest.php
```

## Test Suites

| Suite | Directory | Description |
|-------|-----------|-------------|
| Unit | `tests/Unit/` | Unit tests for individual classes and traits |
| Feature | `tests/Feature/` | Feature tests for integrated behavior |
| MultiTenant | `tests/MultiTenant/` | Tests for multi-tenant middleware and organization resolution |

## Test Environment

- **Database**: SQLite `:memory:` (configured in `phpunit.xml` and `TestCase.php`)
- **Framework**: Orchestra Testbench (Laravel testing outside of a full app)
- **Migrations**: Located in `tests/database/migrations/`
- **Test Models**: Located in `tests/Models/` (registered under `App\Models\` namespace)

---

## Unit Tests

### `PermissionTest`

Tests for the convention-based permission system in `ResourcePolicy`, including `User::hasPermission()`, wildcard support, policy overrides, and organization-scoped permissions.

Uses inline test models (`PermissionPost`) and test policies (`PermissionPostPolicy`, `ExplicitSlugPolicy`, `OverrideWithParentPolicy`, `FullOverridePolicy`) defined at the top of the test file.

#### Basic Permission Checks

| Test | What it verifies |
|------|-----------------|
| `test_user_with_exact_permission_is_allowed` | User with `posts.index` can call `viewAny()` |
| `test_user_without_permission_is_denied` | User with `posts.index` is denied `create()` (requires `posts.store`) |
| `test_guest_user_is_denied` | `null` user is denied on all 5 CRUD methods |

#### Wildcard Permissions

| Test | What it verifies |
|------|-----------------|
| `test_wildcard_grants_all_access` | `['*']` grants all 5 CRUD operations |
| `test_resource_wildcard_grants_all_actions_on_resource` | `['posts.*']` grants all CRUD on posts specifically |

#### Individual Action Mapping

| Test | What it verifies |
|------|-----------------|
| `test_each_action_maps_to_correct_permission` | `viewAny→posts.index`, `view→posts.show`, `create→posts.store`, `update→posts.update`, `delete→posts.destroy` — each permission only grants its action |

#### Multiple Permissions

| Test | What it verifies |
|------|-----------------|
| `test_user_with_multiple_permissions` | User with `['posts.index', 'posts.show', 'posts.store']` is allowed for those 3 but denied `update` and `delete` |

#### User Without Roles

| Test | What it verifies |
|------|-----------------|
| `test_user_without_user_roles_is_denied` | User with no `UserRole` records is denied all actions |

#### Policy Override Patterns

| Test | What it verifies |
|------|-----------------|
| `test_override_with_parent_composition` | Custom `delete()` calls `parent::delete()` AND checks ownership — both conditions must pass |
| `test_override_with_parent_denied_by_permission` | Even if user owns the post, denial by parent permission check stops access |
| `test_full_override_ignores_permissions` | Fully overridden `viewAny()` ignores permissions (just checks authentication); other methods still use defaults |

#### Auto-Resolution of Resource Slug

| Test | What it verifies |
|------|-----------------|
| `test_auto_resolves_slug_from_config` | Policy without explicit `$resourceSlug` resolves it from `global-controller.models` config |

#### Organization-Scoped Permissions

| Test | What it verifies |
|------|-----------------|
| `test_permissions_are_scoped_to_organization` | Same user has `['*']` in org A but read-only `['posts.index', 'posts.show']` in org B — permissions are checked per organization |

#### Backward Compatibility

| Test | What it verifies |
|------|-----------------|
| `test_hidden_columns_still_works_with_permissions` | `ResourcePolicy::hiddenColumns(null)` returns `[]` by default |

---

### `GlobalControllerTest`

| Test | What it verifies |
|------|-----------------|
| `test_global_controller_can_be_instantiated` | `GlobalController` class can be instantiated without errors |

### `HidableColumnsTest`

Tests for the `HidableColumns` trait, `ResourcePolicy` base class, and `HasHiddenColumns` interface.

Uses inline test models (`HidablePost`, `HidablePostWithAdditional`, etc.) and test policies (`HidablePostPolicy`, `PlainPolicy`, `InterfaceOnlyPolicy`, `SpyPolicy`) defined at the top of the test file.

#### Base Behavior

| Test | What it verifies |
|------|-----------------|
| `test_base_hidden_columns_are_always_applied` | `password`, `remember_token`, `created_at`, `updated_at`, `deleted_at` are always hidden via `$baseHiddenColumns` |
| `test_additional_hidden_columns_are_applied` | `$additionalHiddenColumns` property on the model hides the specified columns (backward compatibility) |

#### Policy-Based Hiding

| Test | What it verifies |
|------|-----------------|
| `test_policy_hidden_columns_applied_for_guest_user` | `hiddenColumns(null)` is called when no user is authenticated, hiding the columns returned by the policy |
| `test_policy_hidden_columns_applied_for_regular_user` | Authenticated non-admin user gets restricted columns hidden as defined by the policy |
| `test_policy_hidden_columns_admin_sees_everything` | Admin user (simulated via `id=1`) gets no additional columns hidden when policy returns `[]` |

#### Additive Behavior

| Test | What it verifies |
|------|-----------------|
| `test_policy_columns_are_additive_with_additional_hidden_columns` | Policy hidden columns are merged with `$additionalHiddenColumns` — both sources contribute |
| `test_policy_returning_empty_array_does_not_unhide_base_columns` | Returning `[]` from the policy cannot expose columns already hidden by `$baseHiddenColumns` or `$additionalHiddenColumns` |

#### Fallback Behavior

| Test | What it verifies |
|------|-----------------|
| `test_model_with_no_policy_falls_back_to_static_hidden` | Model with no registered policy gracefully returns only base hidden columns — no errors |
| `test_model_with_plain_policy_not_implementing_interface_falls_back` | Policy that does NOT extend `ResourcePolicy` and does NOT implement `HasHiddenColumns` is ignored — no extra hiding |

#### Interface Support

| Test | What it verifies |
|------|-----------------|
| `test_policy_implementing_interface_directly_works` | A policy that implements `HasHiddenColumns` directly (without extending `ResourcePolicy`) is correctly detected and used |

#### Cache

| Test | What it verifies |
|------|-----------------|
| `test_cache_prevents_multiple_policy_resolutions` | `hiddenColumns()` is called only once when serializing a collection of 3 models (spy policy counts invocations) |
| `test_clear_hidden_columns_cache_resets_cache` | `clearHiddenColumnsCache()` resets the static cache, allowing re-evaluation after the auth context changes |

#### ResourcePolicy

| Test | What it verifies |
|------|-----------------|
| `test_resource_policy_default_returns_empty_array` | Base `ResourcePolicy::hiddenColumns()` returns `[]` by default |
| `test_resource_policy_implements_has_hidden_columns` | `ResourcePolicy` correctly implements the `HasHiddenColumns` interface |

#### Backward Compatibility

| Test | What it verifies |
|------|-----------------|
| `test_hide_additional_columns_method_still_works` | The `hideAdditionalColumns()` instance method continues to work for runtime column hiding |

#### Computed (Virtual) Attributes

Tests that `$appends` accessors (computed attributes) are correctly hidden/shown by policy `hiddenColumns()`, proving that `HidableColumns` applies to virtual attributes the same way it does to database columns.

| Test | What it verifies |
|------|-----------------|
| `test_computed_attributes_are_included_in_response_when_appended` | Model with `$appends = ['rank', 'summary']` includes both computed attributes in `toArray()` output |
| `test_policy_hides_computed_attributes_for_guest` | Guest user (no auth): policy hides both `rank` and `summary` computed attributes |
| `test_policy_hides_computed_attributes_for_regular_user` | Regular user: policy hides `rank` but allows `summary` — selective hiding works for computed attributes |
| `test_admin_sees_all_computed_attributes` | Admin user: policy returns `[]`, so both `rank` and `summary` are visible with correct values |

---

## Feature Tests

### `PaginationTest`

Tests for the pagination feature in `GlobalController::index()`, including on-demand `?per_page` pagination, model-level `$paginationEnabled`, response format consistency, and `X-*` pagination headers.

Uses inline test models (`PaginatedPost`, `PaginatedPostWithPaginationEnabled`, `PaginatedPostWithCustomPerPage`) and a permissive `PaginatedPostPolicy` defined at the top of the test file.

#### Default Behavior (No Pagination)

| Test | What it verifies |
|------|-----------------|
| `test_index_returns_flat_array_without_pagination` | Without `?per_page`, response is a flat JSON array of all items with no `X-Total` header |

#### `?per_page` Query Param

| Test | What it verifies |
|------|-----------------|
| `test_per_page_returns_flat_array_with_pagination_headers` | `?per_page=5` returns 5 items as flat array with `X-Current-Page`, `X-Last-Page`, `X-Per-Page`, `X-Total` headers |
| `test_pagination_navigates_to_second_page` | `?per_page=5&page=2` returns page 2 items starting from "Post 6" |
| `test_pagination_last_page_returns_remaining_items` | Last page returns only the remaining items (e.g., 2 out of 12 at page 3 with per_page=5) |

#### `per_page` Clamping

| Test | What it verifies |
|------|-----------------|
| `test_per_page_is_clamped_to_minimum_of_1` | `?per_page=0` is clamped to 1 |
| `test_per_page_is_clamped_to_maximum_of_100` | `?per_page=500` is clamped to 100 |
| `test_negative_per_page_is_clamped_to_1` | `?per_page=-10` is clamped to 1 |

#### Model-Level `$paginationEnabled`

| Test | What it verifies |
|------|-----------------|
| `test_pagination_enabled_on_model_paginates_by_default` | Model with `$paginationEnabled = true` and `$perPage = 5` paginates even without `?per_page` query param |
| `test_per_page_query_param_overrides_model_default` | `?per_page=10` overrides the model's `$perPage = 5` |
| `test_custom_per_page_on_model` | Model with `$perPage = 3` uses that as default page size |

#### Response Format Consistency

| Test | What it verifies |
|------|-----------------|
| `test_paginated_and_non_paginated_return_same_format` | Both paginated and non-paginated responses return identical flat JSON arrays when all items fit on one page |

#### Authenticated Routes

| Test | What it verifies |
|------|-----------------|
| `test_pagination_works_with_authenticated_routes` | Pagination works correctly on non-public routes with `actingAs` |

#### Empty Results

| Test | What it verifies |
|------|-----------------|
| `test_pagination_with_no_results` | Paginated empty table returns `[]` with `X-Total: 0` |
| `test_no_pagination_with_no_results` | Non-paginated empty table returns `[]` with no pagination headers |

---

### `SoftDeleteTest`

Tests for the soft delete endpoints (`trashed`, `restore`, `forceDelete`) in `GlobalController`, including route registration, CRUD lifecycle, permission checks, and policy overrides.

Uses inline test models (`SoftDeletePost` with `SoftDeletes`, `NonSoftDeletePost` without) and policies (`SoftDeletePostPolicy` permissive, `SoftDeletePostResourcePolicy` permission-based, `SoftDeleteRestrictedRestorePolicy` with ownership override).

#### Route Registration

| Test | What it verifies |
|------|-----------------|
| `test_soft_delete_routes_registered_for_soft_delete_model` | `trashed`, `restore`, `forceDelete` routes are registered for models using `SoftDeletes` |
| `test_soft_delete_routes_not_registered_for_non_soft_delete_model` | No soft delete routes registered for models without `SoftDeletes` |
| `test_soft_delete_routes_respect_except_actions` | All 8 routes (5 CRUD + 3 soft delete) registered alongside each other |

#### GET /trashed — List Soft-Deleted Records

| Test | What it verifies |
|------|-----------------|
| `test_trashed_returns_only_soft_deleted_records` | Only soft-deleted records are returned, not active ones |
| `test_trashed_does_not_return_active_records` | Active records are excluded from the trashed list |
| `test_trashed_returns_empty_when_no_deleted_records` | Returns empty array when no records have been soft-deleted |
| `test_trashed_supports_pagination` | `?per_page` works on the trashed endpoint with `X-*` headers |

#### POST /{id}/restore — Restore a Soft-Deleted Record

| Test | What it verifies |
|------|-----------------|
| `test_restore_brings_back_deleted_record` | Restoring a soft-deleted record clears `deleted_at` and returns the model |
| `test_restore_returns_404_for_non_deleted_record` | Attempting to restore an active record returns 404 (uses `onlyTrashed`) |
| `test_restore_returns_404_for_nonexistent_record` | Attempting to restore a nonexistent ID returns 404 |

#### DELETE /{id}/force-delete — Permanently Delete

| Test | What it verifies |
|------|-----------------|
| `test_force_delete_permanently_removes_record` | Record is completely removed from the database (not just soft-deleted) |
| `test_force_delete_returns_404_for_active_record` | Attempting to force-delete an active record returns 404 (uses `onlyTrashed`) |
| `test_force_delete_returns_404_for_nonexistent_record` | Attempting to force-delete a nonexistent ID returns 404 |

#### Standard Destroy Still Soft-Deletes

| Test | What it verifies |
|------|-----------------|
| `test_destroy_soft_deletes_not_permanent` | `DELETE /{id}` soft-deletes (sets `deleted_at`), record appears in trashed |

#### Full Lifecycle

| Test | What it verifies |
|------|-----------------|
| `test_full_soft_delete_lifecycle` | Complete flow: create → delete → trashed → not in index → restore → back in index → delete → force-delete → gone |

#### Permission Checks

| Test | What it verifies |
|------|-----------------|
| `test_trashed_requires_trashed_permission` | User with `posts.index` but without `posts.trashed` gets 403 |
| `test_trashed_allowed_with_trashed_permission` | User with `posts.trashed` can list trashed records |
| `test_restore_requires_restore_permission` | User with `posts.update` but without `posts.restore` gets 403 |
| `test_restore_allowed_with_restore_permission` | User with `posts.restore` can restore records |
| `test_force_delete_requires_force_delete_permission` | User with `posts.destroy` but without `posts.forceDelete` gets 403 |
| `test_force_delete_allowed_with_force_delete_permission` | User with `posts.forceDelete` can permanently delete |
| `test_wildcard_grants_all_soft_delete_actions` | `['*']` grants trashed, restore, and forceDelete |
| `test_resource_wildcard_grants_soft_delete_actions` | `['posts.*']` grants all soft delete actions on posts |

#### Policy Override

| Test | What it verifies |
|------|-----------------|
| `test_restore_policy_can_be_overridden` | Custom `restore()` composes with `parent::restore()` — user must have permission AND own the record |

#### Non-SoftDeletes Model

| Test | What it verifies |
|------|-----------------|
| `test_trashed_returns_404_for_model_without_soft_deletes` | Model without `SoftDeletes` trait gets 404 for `/trashed` (route not registered) |

---

### `GlobalControllerWithoutMultiTenantTest`

| Test | What it verifies |
|------|-----------------|
| `test_global_controller_works_without_multi_tenant` | Multi-tenant is disabled by default in configuration |
| `test_config_is_properly_set` | Config file has all required keys (`models`, `public`, `multi_tenant`) |

### `RouteRegistrationTest`

Tests for the per-model route registration loop. Uses inline test models with different `$middleware`, `$middlewareActions`, and `$exceptActions` configurations.

#### Basic Route Registration

| Test | What it verifies |
|------|-----------------|
| `test_registers_all_crud_routes_for_model` | All 5 CRUD routes (index, store, show, update, destroy) are registered with correct names |
| `test_registers_routes_for_multiple_models` | Multiple models each get their own set of named routes |
| `test_routes_have_correct_http_methods` | GET for index/show, POST for store, PUT for update, DELETE for destroy |
| `test_routes_have_correct_uri_without_multi_tenant` | Routes use `/api/{slug}` format when multi-tenant is disabled |

#### Multi-Tenant Route Prefixing

| Test | What it verifies |
|------|-----------------|
| `test_routes_have_organization_prefix_with_multi_tenant_route_prefix` | Routes use `/api/{organization}/{slug}` when multi-tenant with route prefix is enabled |
| `test_routes_have_no_organization_prefix_with_subdomain_multi_tenant` | Routes use `/api/{slug}` (no `{organization}`) when subdomain multi-tenant is enabled |

#### Middleware

| Test | What it verifies |
|------|-----------------|
| `test_auth_middleware_applied_to_non_public_models` | `auth:sanctum` middleware is present on non-public model routes |
| `test_auth_middleware_not_applied_to_public_models` | `auth:sanctum` middleware is absent on public model routes |
| `test_model_level_middleware_applied_to_all_actions` | `$middleware` on the model is applied to all 5 CRUD routes |
| `test_per_action_middleware_applied_only_to_specified_actions` | `$middlewareActions` middleware appears only on the specified actions, not others |
| `test_multi_tenant_middleware_applied_when_enabled` | Organization resolver middleware is present when multi-tenant is enabled |

#### Except Actions

| Test | What it verifies |
|------|-----------------|
| `test_excepted_actions_are_not_registered` | Actions listed in `$exceptActions` are not registered as routes |

#### Route Defaults

| Test | What it verifies |
|------|-----------------|
| `test_model_slug_passed_via_route_defaults` | The model slug is passed to the controller via `->defaults('model', $slug)` |

#### Empty Config

| Test | What it verifies |
|------|-----------------|
| `test_no_crud_routes_registered_when_no_models_configured` | No CRUD routes are registered when the models config is empty |

---

## Audit Trail Tests

### `AuditTrailTest`

Tests for the `HasAuditTrail` trait and `AuditLog` model. There is no built-in audit route; these tests verify logging and the `auditLogs` relationship only.

#### Trait — Logging on Model Events (6 tests)

| Test | What it verifies |
|------|-----------------|
| `test_logs_created_event` | A "created" audit log is written when a model is created, with `new_values` containing the attributes |
| `test_logs_updated_event_with_only_dirty_fields` | Only changed (dirty) fields appear in `old_values` / `new_values`, unchanged fields are excluded |
| `test_does_not_log_update_when_nothing_changed` | No audit log is written when `save()` is called but no attributes actually changed |
| `test_logs_deleted_event` | A "deleted" audit log is written on soft delete, `old_values` contains the record's attributes |
| `test_logs_restored_event` | A "restored" audit log is written when a soft-deleted model is restored (without a duplicate "updated" log) |
| `test_logs_force_deleted_event` | A "force_deleted" audit log is written when a model is permanently deleted |

#### Trait — Excluded Columns (2 tests)

| Test | What it verifies |
|------|-----------------|
| `test_excluded_columns_not_logged` | Columns listed in `$auditExclude` are not included in `new_values` on create |
| `test_excluded_columns_not_logged_on_update` | Columns listed in `$auditExclude` are excluded from both `old_values` and `new_values` on update |

#### Trait — User and Metadata Tracking (3 tests)

| Test | What it verifies |
|------|-----------------|
| `test_logs_authenticated_user_id` | `user_id` is recorded when an authenticated user triggers the event |
| `test_logs_null_user_for_unauthenticated` | `user_id` is `null` when no user is authenticated |
| `test_logs_ip_address` | `ip_address` is captured from the request |

#### Trait — morphMany Relationship (1 test)

| Test | What it verifies |
|------|-----------------|
| `test_audit_logs_relationship_on_model` | `$model->auditLogs` returns the correct collection of `AuditLog` entries for the model |

#### Full Lifecycle (1 test)

| Test | What it verifies |
|------|-----------------|
| `test_full_crud_lifecycle_audit_trail` | Create → update → delete → restore produces the correct 4-event audit trail (asserted via `$post->auditLogs()`) |

---

## Nested Endpoint Tests

### `NestedEndpointTest`

Tests for the `POST /api/nested` endpoint: multiple create/update operations in one request, validated and authorized per operation, executed in a single DB transaction. Response includes full created/updated model content in `results[].data`.

#### Structure validation (4 tests)

| Test | What it verifies |
|------|-----------------|
| `test_nested_missing_operations_returns_422` | Missing or non-array `operations` returns 422 |
| `test_nested_operations_not_array_returns_422` | `operations` as non-array returns 422 |
| `test_nested_operation_missing_id_for_update_returns_422` | Update operation without `id` returns 422 with structure error |
| `test_nested_operation_missing_data_returns_422` | Operation without `data` returns 422 |

#### Per-operation validation (1 test)

| Test | What it verifies |
|------|-----------------|
| `test_nested_validation_failure_returns_422_and_no_db_changes` | Invalid `data` for one operation returns 422, errors keyed to operation; no DB changes |

#### Policy (2 tests)

| Test | What it verifies |
|------|-----------------|
| `test_nested_policy_deny_create_returns_403` | Denied create permission returns 403; no record created |
| `test_nested_policy_deny_update_returns_403` | Denied update permission returns 403; record unchanged |

#### Update 404 (2 tests)

| Test | What it verifies |
|------|-----------------|
| `test_nested_update_unknown_model_returns_422` | Unknown model slug returns 422 |
| `test_nested_update_nonexistent_id_returns_404` | Update with non-existent id returns 404 |

#### Success and response content (1 test)

| Test | What it verifies |
|------|-----------------|
| `test_nested_success_returns_200_with_full_content` | Update + create in one request returns 200; `results` in order with full model `data`; DB state correct |

#### Transaction rollback (1 test)

| Test | What it verifies |
|------|-----------------|
| `test_nested_transaction_rollback_on_second_operation_failure` | When second operation fails (e.g. unique constraint), first operation is rolled back |

#### Config (2 tests)

| Test | What it verifies |
|------|-----------------|
| `test_nested_max_operations_returns_422` | Exceeding `max_operations` returns 422; no changes applied |
| `test_nested_allowed_models_rejects_other_models` | Operation for model not in `allowed_models` returns 422 |

---

## Role-Based Validation Tests

### `RoleBasedValidationTest`

Tests for role-keyed validation rules: `$validationRules` (format only), `$validationRulesStore` / `$validationRulesUpdate` (per-role fields and presence: required, nullable, sometimes). User implements `HasRoleBasedValidation::getRoleSlugForValidation($organization)`; the trait merges presence with base format and restricts validated data to the role's fields.

#### Legacy format (2 tests)

| Test | What it verifies |
|------|-----------------|
| `test_legacy_flat_array_uses_static_rules` | Flat `['title', 'content']` uses `$validationRules` as-is; validated contains both fields |
| `test_legacy_flat_array_fails_when_required_missing` | Legacy validation fails when required field is missing |

#### Role-keyed exact match (2 tests)

| Test | What it verifies |
|------|-----------------|
| `test_role_keyed_admin_receives_all_fields_in_validated` | User with role `admin` gets all role-defined fields in validated data |
| `test_role_keyed_assistant_receives_only_title_and_content_in_validated` | User with role `assistant` gets only `title` and `content`; `blog_id` and `is_published` are not in validated |

#### Wildcard and no match (2 tests)

| Test | What it verifies |
|------|-----------------|
| `test_role_keyed_wildcard_fallback_used_when_role_unknown` | Unknown role falls back to `*` key; receives wildcard fields only |
| `test_role_keyed_no_match_and_no_wildcard_returns_empty_validated` | Role with no config and no `*` yields empty validated (no fields accepted) |

#### Presence and full override (3 tests)

| Test | What it verifies |
|------|-----------------|
| `test_presence_merging_produces_required_plus_base_format` | `required` + base format produces correct rule; missing required fails |
| `test_full_rule_override_replaces_base` | Value containing `|` (e.g. `required\|string\|max:500`) replaces base rule entirely; long string passes |
| `test_full_rule_override_enforces_override_constraint` | Override rule (e.g. max:500) fails when value exceeds limit |

#### User without interface and integration (2 tests)

| Test | What it verifies |
|------|-----------------|
| `test_user_without_interface_falls_back_to_wildcard` | User not implementing `HasRoleBasedValidation` falls back to `*` rules |
| `test_integration_with_real_user_and_organization_resolves_role` | Real `User` with `UserRole`/`Role`/`Organization`; `getRoleSlugForValidation($org)` returns role slug; admin gets more fields in validated than assistant |

---

## Search Tests

### `SearchTest`

Tests for the `?search=term` query parameter on index (and trashed) endpoints. Models opt in via `$allowedSearch` (array of column names). Search builds `OR WHERE LOWER(col) LIKE %term%`; dot notation (e.g. `blog.title`) uses `whereHas` for relationship search.

| Test | What it verifies |
|------|-----------------|
| `test_search_returns_matching_rows` | Rows matching term in any allowed column are returned |
| `test_search_excludes_non_matching` | Non-matching rows are excluded; empty array when no matches |
| `test_search_composes_with_filters` | `?search=` and `?filter[]` work together |
| `test_no_allowed_search_silently_ignores` | Model without `$allowedSearch` ignores `?search=` and returns all |
| `test_search_relationship_dot_notation` | `$allowedSearch` with `blog.title` finds posts by related blog title |
| `test_search_empty_or_missing_returns_all` | No `search` param or empty `search=` returns full list |
| `test_search_with_pagination_headers` | Search works with `per_page`; X-Total and items correct |

---

## Include Authorization Tests

### `IncludeAuthorizationTest`

Tests that requesting `?include=` relationships is authorized per related resource. The user must have **viewAny** on each included (and nested) model; otherwise the API returns **403** with message `You do not have permission to include {name}.`

Uses inline test models (`IncludePost`, `IncludeComment`, `IncludeBlog`) and policies that allow viewAny only for user id 1 on comments and blogs; all users can view posts.

| Test | What it verifies |
|------|-----------------|
| `test_gate_denies_view_any_on_included_resource_for_unauthorized_user` | Policy denies viewAny on included model for user 2; Gate reflects that |
| `test_include_forbidden_returns_403_on_index` | GET index with `?include=comments` as user without viewAny(comments) returns 403 |
| `test_include_forbidden_returns_403_on_show` | GET show with `?include=comments` as user without viewAny(comments) returns 403 |
| `test_include_allowed_returns_200_with_relationship_on_index` | User with viewAny(comments) gets 200 when including comments on index |
| `test_include_allowed_returns_200_with_relationship_on_show` | User with viewAny(comments) gets 200 when including comments on show |
| `test_no_include_returns_200` | Request without `include` param returns 200 (no include auth check) |
| `test_nested_include_forbidden_returns_403` | `?include=blog` when user lacks viewAny(blog) returns 403 |
| `test_nested_include_allowed_returns_200` | User with viewAny(blog) gets 200 when including blog on show |
| `test_multiple_includes_one_forbidden_returns_403` | `?include=blog,comments` returns 403 when first denied include is blog |

---

## Organization Resource Scope Tests

### `OrganizationResourceScopeTest`

Tests that when the API resource **is** the Organization model (multi-tenant enabled), the organization scope restricts results to the **current organization** only. Index returns a single-item list; show returns the current organization only when the route `{id}` matches the current org's key, otherwise 404 (so users cannot probe other orgs).

Uses multi-tenant routes (`/api/{organization}/organizations`), `ResolveOrganizationFromRoute` middleware, and the test `App\Models\Organization` model.

| Test | What it verifies |
|------|-----------------|
| `test_organizations_index_returns_only_current_organization` | GET `/api/org-one/organizations` returns only the current org (one item), not all organizations |
| `test_organizations_show_returns_404_when_route_id_does_not_match_current_organization` | GET `/api/org-one/organizations/2` (id 2 is another org) returns 404 so the user cannot access another org |
| `test_organizations_show_returns_404_for_invalid_route_id` | GET `/api/org-one/organizations/2D` returns 404 for non-matching/invalid id |
| `test_organizations_show_returns_current_organization_when_route_id_matches` | GET `/api/org-one/organizations/1` returns 200 with the current org when the route id matches |

---

## Multi-Tenant Tests

### `ResolveOrganizationFromRouteTest`

Tests for the route-prefix multi-tenant middleware (`/api/{org}/resource`).

| Test | What it verifies |
|------|-----------------|
| `test_middleware_passes_through_when_no_organization_parameter` | Requests without an `organization` route parameter pass through without error |
| `test_middleware_returns_404_when_organization_not_found` | Returns 404 when the organization slug/id doesn't match any record |
| `test_middleware_resolves_organization_by_slug` | Correctly resolves the organization model from the route parameter and attaches it to the request |

### `ResolveOrganizationFromSubdomainTest`

Tests for the subdomain multi-tenant middleware (`org.example.com/api/resource`).

| Test | What it verifies |
|------|-----------------|
| `test_middleware_passes_through_for_main_domain` | Requests to the main domain (no subdomain) pass through without error |
| `test_middleware_resolves_organization_by_subdomain` | Correctly resolves the organization model from the subdomain and attaches it to the request |
| `test_middleware_returns_404_when_organization_not_found` | Returns 404 when the subdomain doesn't match any organization |

---

## Export Postman Tests

### `ExportPostmanTest`

Tests for the `rhino:export-postman` Artisan command that generates a Postman Collection v2.1.

| Test | What it verifies |
|------|------------------|
| `test_collection_json_is_valid_and_has_correct_structure` | Output JSON has `info`, `variable`, `item`; schema is v2.1; project name is applied |
| `test_authentication_folder_is_first` | First folder is "Authentication" with Login, Logout, Password recover, Password reset, Register, Accept invitation |
| `test_models_from_config_appear_as_top_level_folders_with_config_slug_name` | Models appear as top-level folders using the config slug (e.g. `exportPosts`) |
| `test_model_folder_has_action_folders_directly` | Each model folder contains action folders (Index, Show, Store, etc.) directly (no role subfolder) |
| `test_index_has_query_builder_examples` | Index folder contains List all, Filter, Sort, Include, Select fields, Search, Paginate, Combined requests |
| `test_soft_delete_actions_appear_only_for_soft_deletes_models` | Trashed, Restore, Force Delete only appear for models using `SoftDeletes` |
| `test_except_actions_are_excluded` | Actions in `$exceptActions` do not appear in the collection |
| `test_all_requests_have_bearer_token_header` | All model requests include `Authorization: Bearer {{token}}` |
| `test_non_multi_tenant_urls_omit_organization_prefix` | When multi_tenant is disabled, variables and URLs do not use `organization` |
| `test_store_request_has_body_from_validation_rules` | Store folder contains a request with a JSON body derived from validation rules |
| `test_collection_variables_include_base_url_and_model_id` | Collection variables include `baseUrl`, `modelId`, and `token`; `--base-url` is applied |

---

## Authentication Tests

### `AuthenticationTest`

Integration tests for all authentication endpoints in `AuthController`. These tests hit the actual HTTP endpoints with real request payloads, using Laravel Sanctum for token management and the Password facade for password reset flows.

#### Login — `POST /api/auth/login`

| Test | What it verifies |
|------|-----------------|
| `test_login_with_valid_credentials_returns_token` | Valid email/password returns 200 with token and organization slug |
| `test_login_with_invalid_password_returns_401` | Wrong password returns 401 with "Invalid credentials" |
| `test_login_with_non_existent_email_returns_401` | Non-existent email returns 401 |
| `test_login_returns_null_org_slug_when_user_has_no_orgs` | User without organizations gets `organization_slug: null` |
| `test_login_validates_required_fields` | Missing email/password returns 422 with validation errors |

#### Logout — `POST /api/auth/logout`

| Test | What it verifies |
|------|-----------------|
| `test_logout_revokes_tokens` | Authenticated user gets 200, all tokens deleted from DB |
| `test_logout_requires_authentication` | Unauthenticated request returns 401 |

#### Password Recovery — `POST /api/auth/password/recover`

| Test | What it verifies |
|------|-----------------|
| `test_recover_password_sends_reset_link` | Valid email returns 200 with success message |
| `test_recover_password_returns_500_on_failure` | Password broker failure returns 500 |
| `test_recover_password_validates_email` | Missing email returns 422 |
| `test_recover_password_validates_email_format` | Invalid email format returns 422 |

#### Password Reset — `POST /api/auth/password/reset`

| Test | What it verifies |
|------|-----------------|
| `test_reset_password_with_valid_token` | Valid token resets password, returns 200, password actually changed in DB |
| `test_reset_password_with_invalid_token_returns_400` | Invalid/expired token returns 400 |
| `test_reset_password_validates_required_fields` | Missing fields returns 422 |
| `test_reset_password_requires_password_confirmation` | Missing password_confirmation returns 422 |
| `test_reset_password_requires_minimum_8_characters` | Short password returns 422 |
| `test_reset_password_requires_existing_email` | Non-existent email returns 422 |

#### Registration with Invitation — `POST /api/auth/register`

| Test | What it verifies |
|------|-----------------|
| `test_register_with_valid_invitation` | Valid invitation creates user, accepts invitation, attaches to org, returns 201 with token |
| `test_register_with_invalid_token_returns_404` | Non-existent token returns 404 |
| `test_register_with_expired_invitation_returns_422` | Expired invitation returns 422, status updated to "expired" |
| `test_register_with_email_mismatch_returns_422` | Email not matching invitation returns 422 |
| `test_register_validates_required_fields` | Missing fields returns 422 |
| `test_register_requires_unique_email` | Already-taken email returns 422 |
| `test_register_requires_password_confirmation` | Missing password_confirmation returns 422 |
| `test_register_token_must_be_64_chars` | Token not exactly 64 chars returns 422 |

---

## Test Summary

| Suite | File | Tests |
|-------|------|:-----:|
| Unit | `PermissionTest.php` | 13 |
| Unit | `GlobalControllerTest.php` | 1 |
| Unit | `HidableColumnsTest.php` | 19 |
| Feature | `AuditTrailTest.php` | 13 |
| Feature | `SoftDeleteTest.php` | 25 |
| Feature | `PaginationTest.php` | 14 |
| Feature | `GlobalControllerWithoutMultiTenantTest.php` | 2 |
| Feature | `RouteRegistrationTest.php` | 14 |
| Feature | `NestedEndpointTest.php` | 13 |
| Feature | `RoleBasedValidationTest.php` | 11 |
| Feature | `SearchTest.php` | 7 |
| Feature | `IncludeAuthorizationTest.php` | 9 |
| Feature | `OrganizationResourceScopeTest.php` | 4 |
| Feature | `ExportPostmanTest.php` | 11 |
| Feature | `AuthenticationTest.php` | 25 |
| MultiTenant | `ResolveOrganizationFromRouteTest.php` | 3 |
| MultiTenant | `ResolveOrganizationFromSubdomainTest.php` | 3 |
| | **Total** | **188** |
