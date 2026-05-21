# /rhino:audit — Security Audit

You are performing a comprehensive security audit on an Rhino project.

## Audit Checklist

### 1. Mass Assignment
For each model in config/rhino.php:
- Check $fillable is explicitly defined (not ['*'])
- Check no sensitive fields (is_admin, role, password, etc.) in $fillable
- Check permittedAttributesForCreate/Update in the policy

### 2. Authorization (IDOR)
For each model:
- Policy exists and extends ResourcePolicy
- hiddenColumns() is implemented
- Test: Can user A access user B's records?
- Test: Can a viewer perform admin actions?

### 3. Multi-Tenant Isolation
For each model:
- Has BelongsToOrganization trait (direct) or BelongsTo chain (indirect)
- Test: org A's data is invisible to org B
- No queries bypass tenant scoping (no Model::all() without scope)

### 4. Validation
For each model:
- $validationRules covers all fillable fields
- Required fields are marked required
- Enums/options are validated with 'in:' rule
- Foreign keys use 'exists:' rule (auto-scoped to tenant)

### 5. Authentication
- Auth endpoints have rate limiting
- Tokens expire appropriately
- Password reset tokens are single-use

### 6. Secrets
- No API keys in source code
- .env file is in .gitignore
- No secrets in config files (all use env())

### 7. SQL Injection
- No raw queries with string interpolation
- All filtering uses Rhino query builder ($allowedFilters)

## Output

Generate a security report with severity levels:
- CRITICAL: Must fix immediately
- HIGH: Fix before deploy
- MEDIUM: Fix soon
- LOW: Improvement opportunity
