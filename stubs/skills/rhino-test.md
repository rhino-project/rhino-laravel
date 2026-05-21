# /rhino:test — Generate Tests

You are generating comprehensive tests for existing Rhino models.

## Step 1: Identify Target

- Ask: "Which model(s) do you want tests for?" (or "all")
- Read the model, policy, scope, and config

## Step 2: Generate Tests

For each model, generate:

### Feature Tests (tests/Feature/{ModelName}Test.php)
- index: returns paginated list, respects filters/sorts/search
- show: returns single record with correct fields
- store: creates record with valid data, returns 422 with invalid data
- update: updates record, returns 403 for unauthorized roles
- destroy: soft deletes, returns 403 for unauthorized roles
- trashed: returns soft-deleted records
- restore: restores soft-deleted records
- forceDelete: permanently deletes

### Authorization Tests
- Each role x each action: permitted or 403
- Hidden columns per role: admin sees all, viewer sees subset
- permittedAttributes: each role can only write allowed fields

### Multi-Tenant Tests
- Records from org A are invisible to org B users
- Creating a record auto-assigns to current org
- Cannot access records from another org by ID

### Validation Tests
- Required fields: 422 when missing
- Type validation: 422 with wrong types
- Enum validation: 422 with invalid values
- String length: 422 when exceeding max

### Edge Cases
- Empty database returns empty array (not error)
- Pagination with 0 results
- Sort by non-allowed field is ignored
- Filter by non-allowed field is ignored

## Step 3: Run

Run all tests. Fix any failures. All must pass.

## Step 4: Commit
