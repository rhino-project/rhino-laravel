# /rhino:review — Code Review

You are reviewing code changes in an Rhino project for security issues, missing tests, and convention violations.

## What to Check

### Security
- [ ] No mass assignment vulnerabilities ($fillable properly defined)
- [ ] Policy exists and extends ResourcePolicy
- [ ] hiddenColumns() hides sensitive fields from non-admin roles
- [ ] permittedAttributesForCreate/Update restrict writable fields
- [ ] Scope filters data by role if needed
- [ ] Multi-tenant isolation: BelongsToOrganization or indirect chain exists
- [ ] No raw SQL queries (use Rhino query builder)
- [ ] No hardcoded secrets
- [ ] Validation rules exist for all user inputs
- [ ] Cross-tenant FK validation if foreign keys reference other tenant models

### Rhino Conventions
- [ ] Model uses required traits: HasValidation, HidableColumns, SoftDeletes, HasFactory
- [ ] All query properties defined: $allowedFilters, $allowedSorts, $allowedIncludes, $allowedSearch
- [ ] Model registered in config/rhino.php
- [ ] No business logic in controllers (Rhino generates controllers automatically)
- [ ] Policy uses rolesInOrganization() pattern for role checking
- [ ] Validation rules use role-keyed format if different roles have different permissions

### Tests
- [ ] Feature tests exist for all CRUD operations
- [ ] Tests cover every role (admin, editor, viewer, *)
- [ ] Tests verify hidden columns per role
- [ ] Tests verify validation (valid + invalid data)
- [ ] Tests verify multi-tenant isolation
- [ ] Edge cases covered (empty data, max length, special characters)

### Architecture
- [ ] No 500-line files
- [ ] Separation of concerns (model/policy/scope/factory)
- [ ] Consistent patterns with existing codebase
- [ ] API responses follow Rhino standard format

## Output

Present findings as:
- CRITICAL (security vulnerabilities, data leaking)
- WARNING (missing tests, convention violations)
- GOOD (things done correctly)
